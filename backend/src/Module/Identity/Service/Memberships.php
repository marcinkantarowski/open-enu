<?php

declare(strict_types=1);

namespace App\Module\Identity\Service;

use App\Module\Identity\Contract\MembershipsInterface;
use App\Module\Identity\Contract\UserDirectoryInterface;
use App\Module\Identity\Entity\Membership;
use App\Module\Identity\Entity\SecurityToken;
use App\Module\Identity\Entity\User;
use App\Module\Identity\Repository\MembershipRepository;
use App\Module\Identity\Repository\SecurityTokenRepository;
use App\Module\Identity\Repository\UserRepository;
use App\Module\Role\Contract\RoleHoldersInterface;
use App\Module\Tenant\Contract\TenantReaderInterface;
use OpenEnu\Kernel\Doctrine\ScopeContext;
use OpenEnu\Kernel\Dto\ConflictBody;
use OpenEnu\Kernel\Http\Exception\ConflictException;
use OpenEnu\Kernel\Crypto\Encryptor;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Everything other modules may know or change about memberships: the operator
 * console's directory, the Role module's "who holds this", and the Member
 * module's administration. One class, because it is one question - who is in
 * this tenant, and as what.
 */
#[AsAlias(RoleHoldersInterface::class)]
final readonly class Memberships implements MembershipsInterface, UserDirectoryInterface, RoleHoldersInterface
{
    public function __construct(
        private UserRepository $users,
        private MembershipRepository $memberships,
        private SecurityTokenRepository $tokenRows,
        private SecurityTokenIssuer $tokens,
        private Encryptor $encryptor,
        private UserPasswordHasherInterface $hasher,
        private IdentityMailer $mailer,
        private TenantReaderInterface $tenants,
        private ScopeContext $scope,
    ) {
    }

    public function inTenant(string $tenantId): array
    {
        return array_map(self::describe(...), $this->memberships->forTenant($tenantId));
    }

    public function find(string $tenantId, string $membershipId): ?array
    {
        $membership = $this->membership($tenantId, $membershipId);

        return $membership !== null ? self::describe($membership) : null;
    }

    public function assign(string $tenantId, string $membershipId, string $role, ?array $unitIds, ?int $expectedVersion = null): void
    {
        $membership = $this->require($tenantId, $membershipId);

        if ($expectedVersion !== null && $expectedVersion !== $membership->version()) {
            throw new ConflictException(new ConflictBody('Member', $membershipId, $expectedVersion, $membership->version(), self::describe($membership)));
        }

        $membership->changeRole($role);
        $membership->narrowTo($unitIds);
    }

    public function suspend(string $tenantId, string $membershipId): void
    {
        $this->require($tenantId, $membershipId)->suspend();
    }

    public function reactivate(string $tenantId, string $membershipId): void
    {
        $membership = $this->require($tenantId, $membershipId);
        if ($membership->status() !== Membership::STATUS_SUSPENDED) {
            throw new ConflictHttpException('Only a suspended membership can be reactivated.');
        }

        $membership->activate();
    }

    public function invite(string $tenantId, string $email, string $role, ?array $unitIds): array
    {
        $unsaved = [];
        $user = $this->users->byEmailHash($this->encryptor->hashForLookup($email));

        if ($user === null) {
            // No password: nobody can sign in to it until the link is used.
            $user = new User($email, $this->encryptor->hashForLookup($email));
            $unsaved[] = $user;
        }

        $membership = $this->anyMembership($user, $tenantId);
        if ($membership !== null && $membership->status() !== Membership::STATUS_INVITED) {
            throw new ConflictHttpException('That address is already a member here.');
        }

        // Saved through the user's cascade, whether the user is new or not.
        $membership ??= new Membership($user, $tenantId, $role);
        $membership->invite();
        $membership->changeRole($role);
        $membership->narrowTo($unitIds);

        [$token, $raw] = $this->tokens->issue(
            SecurityToken::PURPOSE_INVITATION,
            $user,
            ['membershipId' => (string) $membership->id()],
            $tenantId,
        );
        $unsaved[] = $token;

        return ['membershipId' => (string) $membership->id(), 'token' => $raw, 'unsaved' => $unsaved];
    }

    public function mailInvitation(string $tenantId, string $email, string $rawToken): void
    {
        $user = $this->users->byEmailHash($this->encryptor->hashForLookup($email));
        $locale = $user?->locale() ?? $this->tenants->defaultLocale($tenantId) ?? 'en';

        $this->mailer->sendInvitation($email, $rawToken, $this->tenants->describe($tenantId)['name'] ?? '', $locale);
    }

    public function describeInvitation(string $rawToken): ?array
    {
        $token = $this->tokenRows->byHash($this->tokens->hash($rawToken), SecurityToken::PURPOSE_INVITATION);
        $user = $token?->user();
        $membership = $token !== null && $user !== null ? $this->invited($token, $user) : null;

        if ($membership === null || $user === null || !$token?->isUsable()) {
            return null;
        }

        return [
            'tenantId' => $membership->tenantId(),
            'tenantName' => $this->tenants->describe($membership->tenantId())['name'] ?? '',
            'email' => $user->email(),
            'needsPassword' => $user->getPassword() === '',
        ];
    }

    public function acceptInvitation(string $rawToken, ?string $plainPassword, ?string $displayName): array
    {
        $token = $this->tokens->redeem($rawToken, SecurityToken::PURPOSE_INVITATION);
        $user = $token?->user();
        $membership = $token !== null && $user !== null ? $this->invited($token, $user) : null;

        if ($membership === null || $user === null) {
            throw new BadRequestHttpException('This invitation is invalid, has expired, or was withdrawn.');
        }

        if ($user->getPassword() === '') {
            if ($plainPassword === null) {
                throw new BadRequestHttpException('A new account needs a password.');
            }
            $user->setPassword($this->hasher->hashPassword($user, $plainPassword));
            $user->setDisplayName($displayName);
        }

        // The link arrived at the address: that is what verifying it means.
        $user->verify();
        $membership->activate();

        return ['tenantId' => $membership->tenantId(), 'membershipId' => (string) $membership->id(), 'email' => $user->email()];
    }

    public function holding(string $tenantId, string $role): int
    {
        return $this->memberships->countWithRole($tenantId, $role);
    }

    public function membersOf(string $tenantId): array
    {
        // Unscoped because the caller is the operator console, which is
        // deliberately outside any tenant - and `User` is not tenant-scoped
        // anyway, so this is about the memberships it joins through.
        return $this->scope->runUnscoped(
            'listing a tenant\'s members for the operator console',
            function () use ($tenantId): array {
                $rows = [];

                foreach ($this->users->membersOf($tenantId) as $user) {
                    $rows[] = [
                        'id' => (string) $user->id(),
                        'email' => $user->email(),
                        'displayName' => $user->displayName(),
                        'role' => $user->membershipIn($tenantId)?->role() ?? 'unknown',
                        'status' => $user->status(),
                    ];
                }

                return $rows;
            },
        );
    }

    public function countMembersOf(string $tenantId): int
    {
        return $this->scope->runUnscoped(
            'counting a tenant\'s members for the operator console',
            fn (): int => $this->memberships->countActiveForTenant($tenantId),
        );
    }

    /** The membership a token was issued for, if it is still an open invitation for that user. */
    private function invited(SecurityToken $token, User $user): ?Membership
    {
        $id = $token->payload()['membershipId'] ?? null;
        $membership = \is_string($id) && Uuid::isValid($id) ? $this->memberships->find(Uuid::fromString($id)) : null;

        return $membership !== null
            && $membership->user() === $user
            && $membership->status() === Membership::STATUS_INVITED
            ? $membership
            : null;
    }

    private function anyMembership(User $user, string $tenantId): ?Membership
    {
        foreach ($user->memberships() as $membership) {
            if ($membership->tenantId() === $tenantId) {
                return $membership;
            }
        }

        return null;
    }

    private function membership(string $tenantId, string $membershipId): ?Membership
    {
        $membership = Uuid::isValid($membershipId) ? $this->memberships->find(Uuid::fromString($membershipId)) : null;

        // Another tenant's membership is as absent as one that does not exist.
        return $membership?->tenantId() === $tenantId ? $membership : null;
    }

    private function require(string $tenantId, string $membershipId): Membership
    {
        return $this->membership($tenantId, $membershipId) ?? throw new NotFoundHttpException('No such member.');
    }

    /** @return array{id: string, userId: string, email: string, displayName: ?string, role: string, unitIds: list<string>|null, status: string, joinedAt: string, version: int} */
    private static function describe(Membership $membership): array
    {
        $user = $membership->user();

        return [
            'id' => (string) $membership->id(),
            'userId' => (string) $user->id(),
            'email' => $user->email(),
            'displayName' => $user->displayName(),
            'role' => $membership->role(),
            'unitIds' => $membership->unitIds(),
            'status' => $membership->status(),
            'joinedAt' => $membership->createdAt()->format(\DATE_ATOM),
            'version' => $membership->version(),
        ];
    }
}
