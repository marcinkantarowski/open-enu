# Attachment

Owns files that any module can attach to its records.

## Owns

- `Attachment` - the stored file's metadata and what it belongs to. The owner is a
  `type`/`id` pair rather than a relation: an attachment must be able to belong to any
  module's record, and a foreign key to each would be exactly the coupling the boundaries
  forbid.
- Upload, which goes through `StorageInterface` so keys are tenant-prefixed and generated.

## Public contracts

_(none yet.)_

## Events

_(none yet.)_

## Permissions

- `attachment.view`
- `attachment.manage`

## Notes for agents

- The original filename is metadata, **never** the storage key. The key is a generated ULID
  under `tenants/{tid}/attachment/`, so a user-supplied name can never become a path.
- The client-supplied content type is not trusted; the bytes are sniffed.
- Downloads are signed, expiring URLs. A storage URL that works forever is a credential, and
  it ends up in a chat message.
- Orphans - uploaded, never attached - are swept, because nothing references them and they
  are otherwise invisible.
