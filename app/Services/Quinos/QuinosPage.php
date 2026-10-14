<?php

namespace App\Services\Quinos;

/**
 * One Inertia page answer from Quinos, reduced to the parts we use.
 *
 * `auth` (it contains the user's PIN and company token) and `ziggy` (the whole
 * route list) are dropped on purpose; only the company id and name are kept.
 */
final class QuinosPage
{
    public function __construct(
        public readonly string $component,
        public readonly array $props,
        public readonly string $url,
        public readonly ?string $version,
        public readonly ?string $companyId,
        public readonly ?string $companyName,
    ) {}

    public static function fromArray(array $page): self
    {
        $props = $page['props'] ?? [];
        $company = $props['auth']['user']['company'] ?? [];

        unset($props['auth'], $props['ziggy']);

        return new self(
            component: (string) ($page['component'] ?? ''),
            props: $props,
            url: (string) ($page['url'] ?? ''),
            version: isset($page['version']) ? (string) $page['version'] : null,
            companyId: isset($company['id']) ? (string) $company['id'] : null,
            companyName: isset($company['name']) ? (string) $company['name'] : null,
        );
    }

    /** Rows of a list page (props.rows.data). */
    public function rows(): array
    {
        return $this->props['rows']['data'] ?? [];
    }

    public function nextCursor(): ?string
    {
        $cursor = $this->props['rows']['meta']['next_cursor'] ?? null;

        return filled($cursor) ? (string) $cursor : null;
    }

    public function isLoginPage(): bool
    {
        return str_starts_with($this->component, 'Auth/Login');
    }
}
