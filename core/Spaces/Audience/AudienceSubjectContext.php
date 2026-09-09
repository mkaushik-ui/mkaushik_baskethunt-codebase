<?php
declare(strict_types=1);

namespace SOI\Core\Spaces\Audience;

use SOI\Core\Auth;
use SOI\Core\SoiCentralAuth;

/**
 * AudienceSubjectContext
 *
 * Immutable value object normalizing the current requesting identity (guest vs. authenticated user,
 * roles, department, team, groups, and explicit permissions) across the SOI Knowledge Center.
 *
 * Follows Single Source of Truth (SSOT) by sourcing claims from SOI Accounts / SoiCentralAuth session.
 */
final class AudienceSubjectContext
{
    private ?int $id;
    private ?string $email;
    private string $role;
    private ?string $department;
    private ?string $team;
    /** @var array<string> */
    private array $groups;
    /** @var array<string> */
    private array $permissions;
    private bool $isLoggedIn;

    /**
     * @param int|null $id Local user ID
     * @param string|null $email User email
     * @param string $role CMS Role (admin, editor, author, subscriber, guest)
     * @param string|null $department Organizational department (e.g. HR, IT, Finance)
     * @param string|null $team Departmental team (e.g. Recruitment, Infrastructure)
     * @param array<string> $groups Access/Directory groups
     * @param array<string> $permissions Specific capability flags
     * @param bool $isLoggedIn True if session is authenticated
     */
    public function __construct(
        ?int $id = null,
        ?string $email = null,
        string $role = 'guest',
        ?string $department = null,
        ?string $team = null,
        array $groups = [],
        array $permissions = [],
        bool $isLoggedIn = false
    ) {
        $this->id = $id;
        $this->email = $email !== null ? strtolower(trim($email)) : null;
        $this->role = strtolower(trim($role)) ?: 'guest';
        $this->department = $department !== null ? trim($department) : null;
        $this->team = $team !== null ? trim($team) : null;
        $this->groups = array_values(array_unique(array_filter(array_map('trim', $groups))));
        $this->permissions = array_values(array_unique(array_filter(array_map('trim', $permissions))));
        $this->isLoggedIn = $isLoggedIn;
    }

    /**
     * Build subject context from the active session (Auth & SoiCentralAuth claims).
     */
    public static function fromCurrentSession(): self
    {
        if (!class_exists(Auth::class) || !Auth::check()) {
            return self::guest();
        }

        $user = Auth::user();
        $userId = Auth::id();
        $email = $user['email'] ?? null;
        $role = Auth::role() ?: 'subscriber';

        $department = null;
        $team = null;
        $groups = [];
        $permissions = [];

        if (class_exists(SoiCentralAuth::class)) {
            $profile = SoiCentralAuth::currentSessionProfile();
            if (is_array($profile)) {
                $department = $profile['department'] ?? null;
                $team = $profile['team'] ?? null;
                $groups = $profile['app_roles'] ?? [];
                $permissions = $profile['app_permissions'] ?? [];
            }
        }

        return new self(
            $userId,
            $email,
            $role,
            $department,
            $team,
            $groups,
            $permissions,
            true
        );
    }

    /**
     * Build an anonymous guest subject context.
     */
    public static function guest(): self
    {
        return new self(
            null,
            null,
            'guest',
            null,
            null,
            [],
            [],
            false
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function getTeam(): ?string
    {
        return $this->team;
    }

    /**
     * @return array<string>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return array<string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    public function isLoggedIn(): bool
    {
        return $this->isLoggedIn;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasGroup(string $group): bool
    {
        return in_array(strtolower(trim($group)), array_map('strtolower', $this->groups), true);
    }

    public function hasPermission(string $permission): bool
    {
        return in_array(strtolower(trim($permission)), array_map('strtolower', $this->permissions), true);
    }
}
