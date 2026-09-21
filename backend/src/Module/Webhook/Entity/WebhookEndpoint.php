<?php

declare(strict_types=1);

namespace App\Module\Webhook\Entity;

use App\Module\Webhook\Repository\WebhookEndpointRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use OpenEnu\Kernel\Contract\TenantScopedInterface;
use OpenEnu\Kernel\Contract\VersionedInterface;
use OpenEnu\Kernel\Doctrine\Type\EncryptedTenantStringType;
use Symfony\Component\Uid\Uuid;

/**
 * Where one tenant wants its events sent.
 *
 * The secret is encrypted at rest and shown exactly once, at creation - it is a
 * signing key, and a key that can be read back from a list endpoint is one an
 * attacker can read back too.
 */
#[ORM\Entity(repositoryClass: WebhookEndpointRepository::class)]
#[ORM\Table(name: 'webhook_endpoint')]
#[ORM\Index(columns: ['tenant_id'], name: 'idx_webhook_endpoint_tenant')]
class WebhookEndpoint implements TenantScopedInterface, VersionedInterface
{
    #[ORM\Column(name: 'tenant_id', type: 'uuid')]
    private Uuid $tenantId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $url;

    /**
     * Event names this endpoint wants, e.g. `example.project.created`.
     *
     * Names rather than classes: the name is the wire contract (`eventName()`),
     * and a subscriber outside this repository cannot know a PHP class.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $events = [];

    /** Shown once, at creation. Ciphertext here; never returned again. */
    #[ORM\Column(type: EncryptedTenantStringType::NAME)]
    private string $secret;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $active = true;

    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @param list<string> $events */
    public function __construct(string $tenantId, string $url, array $events, string $secret)
    {
        $this->id = Uuid::v7();
        $this->tenantId = Uuid::fromString($tenantId);
        $this->url = $url;
        $this->events = array_values($events);
        $this->secret = $secret;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function tenantId(): string
    {
        return (string) $this->tenantId;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function secret(): string
    {
        return $this->secret;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function deactivate(): void
    {
        $this->active = false;
    }

    public function wants(string $eventName): bool
    {
        return $this->active && \in_array($eventName, $this->events, true);
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => (string) $this->id,
            'url' => $this->url,
            'events' => $this->events,
            'active' => $this->active,
            // Never the secret. It exists in clear exactly once, in the response
            // to the request that created it.
            'version' => $this->version,
            'createdAt' => $this->createdAt->format(\DATE_ATOM),
        ];
    }
}
