<?php
declare(strict_types=1);

namespace SOI\Core\Spaces\Audience;

use JsonSerializable;
use SOI\Core\Auth;
use SOI\Core\SoiCentralAuth;

/**
 * AudienceSubjectContext
 *
 * Immutable, normalized representation of the requesting subject (guest, authenticated user,
 * department member, team member, group member, or space manager) in SOI Knowledge Center.
 *
 * Bridges SOI\Core\Auth and SoiCentralAuth claims without coupling domain services
 * directly to PHP superglobals or external session lifecycles.
 */
final class AudienceSubjectContext implements JsonSerializable
{
    private ?int $id;
    private ?string $email;
    private string $role;
    private ?string $department;
    private ?string $team;
    /** @var list<string> */
    private array $groups;
    /** @var list<string> */
    private array $permissions;
    private bool $isLoggedIn;
    /** @var array<string, mixed> */
    private array $metadata;

    /**
     * @param int|null $id
     * @param string|null $email
     * @param string $role
     * @param string|null $department
     * @param string|null $team
     * @param list<string>|array $groups
     * @param list<string>|array $permissions
     * @param bool $isLoggedIn
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        ?int $id = null,
        ?string $email = null,
        string $role = '',
        ?string $department = null,
        ?string $team = null,
        array $groups = [],
        array $permissions = [],
        bool $isLoggedIn = false,
        array $metadata = []
    ) {
        $this->id = ($id !== null && $id > 0) ? (int) $id : null;
        $this->email = ($email !== null && trim($email) !== '') ? strtolower(trim($email)) : null;
        $this->role = strtolower(trim($role));
        $this->department = ($department !== null && trim($department) !== '') ? trim($department) : null;
        $this->team = ($team !== null && trim($team) !== '') ? trim($team) : null;
        $this->groups = self::normalizeStringList($groups);
        $this->permissions = self::normalizeStringList($permissions);
        $this->isLoggedIn = $isLoggedIn && ($this->id !== null || $this->email !== null || $this->role !== '');
        $this->metadata = $metadata;
    }

    /**
     * Construct an anonymous guest subject context.
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
            false,
            ['type' => 'guest']
        );
    }

    /**
     * Construct an authenticated user subject context.
     *
     * @param int $id
     * @param string $email
     * @param string $role
     * @param string|null $department
     * @param string|null $team
     * @param list<string>|array $groups
     * @param list<string>|array $permissions
     * @param array<string, mixed> $metadata
     */
    public static function forUser(
        int $id,
        string $email,
        string $role = 'subscriber',
        ?string $department = null,
        ?string $team = null,
        array $groups = [],
        array $permissions = [],
        array $metadata = []
    ): self {
        return new self(
            $id,
            $email,
            $role,
            $department,
            $team,
            $groups,
            $permissions,
            true,
            $metadata
        );
    }

    /**
     * Create subject context from current PHP session / Auth / SoiCentralAuth state.
     */
    public static function fromCurrentSession(): self
    {
        // 1. Check if user is logged in via Auth or raw session
        $isAuthChecked = class_exists(Auth::class) && Auth::check();
        $rawSessionUser = $_SESSION['soi_user'] ?? null;

        if (!$isAuthChecked && empty($rawSessionUser)) {
            return self::guest();
        }

        // 2. Extract base user details
        $user = class_exists(Auth::class) ? Auth::user() : $rawSessionUser;
        if (!is_array($user) || empty($user)) {
            return self::guest();
        }

        $id = class_exists(Auth::class) ? Auth::id() : ($user['id'] ?? null);
        $role = class_exists(Auth::class) ? Auth::role() : ($user['role'] ?? 'subscriber');
        $email = (string) ($user['email'] ?? '');

        // 3. Extract Central profile claims
        $centralProfile = [];
        if (class_exists(SoiCentralAuth::class) && is_callable([SoiCentralAuth::class, 'currentSessionProfile'])) {
            try {
                $centralProfile = SoiCentralAuth::currentSessionProfile() ?: [];
            } catch (\Throwable) {
                $centralProfile = [];
            }
        }

        if (empty($centralProfile)) {
            if (!empty($user['soi_central_profile']) && is_array($user['soi_central_profile'])) {
                $centralProfile = $user['soi_central_profile'];
            } elseif (!empty($_SESSION['soi_central_profile']) && is_array($_SESSION['soi_central_profile'])) {
                $centralProfile = $_SESSION['soi_central_profile'];
            }
        }

        // 4. Resolve Department
        $department = $centralProfile['department']
            ?? $user['department']
            ?? ($_SESSION['soi_access']['department'] ?? null);
        if (is_string($department)) {
            $department = trim($department) !== '' ? trim($department) : null;
        } else {
            $department = null;
        }

        // 5. Resolve Team
        $team = $centralProfile['team']
            ?? $user['team']
            ?? ($_SESSION['soi_access']['team'] ?? null);
        if (is_string($team)) {
            $team = trim($team) !== '' ? trim($team) : null;
        } else {
            $team = null;
        }

        // 6. Resolve Groups and Roles
        $groupCandidates = [];
        if (!empty($centralProfile['groups'])) {
            $groupCandidates = array_merge($groupCandidates, (array) $centralProfile['groups']);
        }
        if (!empty($centralProfile['app_roles'])) {
            $groupCandidates = array_merge($groupCandidates, (array) $centralProfile['app_roles']);
        }
        if (!empty($centralProfile['roles'])) {
            $groupCandidates = array_merge($groupCandidates, (array) $centralProfile['roles']);
        }
        if (!empty($user['soi_central_roles'])) {
            $groupCandidates = array_merge($groupCandidates, (array) $user['soi_central_roles']);
        }
        if (!empty($user['groups'])) {
            $groupCandidates = array_merge($groupCandidates, (array) $user['groups']);
        }
        if (!empty($_SESSION['soi_access']['roles'])) {
            $groupCandidates = array_merge($groupCandidates, (array) $_SESSION['soi_access']['roles']);
        }
        $groups = self::normalizeStringList($groupCandidates);

        // 7. Resolve Permissions
        $permCandidates = [];
        if (!empty($centralProfile['app_permissions'])) {
            $permCandidates = array_merge($permCandidates, (array) $centralProfile['app_permissions']);
        }
        if (!empty($centralProfile['permissions'])) {
            $permCandidates = array_merge($permCandidates, (array) $centralProfile['permissions']);
        }
        if (!empty($user['soi_central_permissions'])) {
            $permCandidates = array_merge($permCandidates, (array) $user['soi_central_permissions']);
        }
        if (!empty($_SESSION['soi_access']['permissions'])) {
            $permCandidates = array_merge($permCandidates, (array) $_SESSION['soi_access']['permissions']);
        }
        if (!empty($user['permissions'])) {
            $permCandidates = array_merge($permCandidates, (array) $user['permissions']);
        }

        // Add standard fallback role permissions if none present
        if (empty($permCandidates)) {
            $permCandidates = self::defaultPermissionsForRole((string) $role);
        }
        $permissions = self::normalizeStringList($permCandidates);

        // 8. Compile Metadata
        $metadata = [
            'username'           => $user['username'] ?? ($centralProfile['username'] ?? null),
            'display_name'       => $user['display_name'] ?? ($centralProfile['display_name'] ?? null),
            'soi_user_id'        => $user['soi_central_user_id'] ?? ($centralProfile['soi_user_id'] ?? null),
            'app_access_status'  => $centralProfile['app_access_status'] ?? ($_SESSION['soi_access']['status'] ?? null),
            'access_version'     => $centralProfile['access_version'] ?? ($user['soi_central_access_version'] ?? null),
            'session_authenticated_at' => $user['last_login'] ?? date('Y-m-d H:i:s'),
        ];

        return new self(
            $id !== null ? (int) $id : null,
            $email !== '' ? $email : null,
            (string) $role,
            $department,
            $team,
            $groups,
            $permissions,
            true,
            $metadata
        );
    }

    /**
     * Reconstruct instance from an associative array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['id']) ? (int) $data['id'] : null,
            isset($data['email']) ? (string) $data['email'] : null,
            (string) ($data['role'] ?? ''),
            isset($data['department']) ? (string) $data['department'] : null,
            isset($data['team']) ? (string) $data['team'] : null,
            (array) ($data['groups'] ?? []),
            (array) ($data['permissions'] ?? []),
            !empty($data['is_logged_in']) || !empty($data['isLoggedIn']),
            (array) ($data['metadata'] ?? [])
        );
    }

    // =========================================================================
    // Immutable Accessors
    // =========================================================================

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
     * @return list<string>
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * @return list<string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function isLoggedIn(): bool
    {
        return $this->isLoggedIn;
    }

    /**
     * Whether the subject holds admin privileges.
     */
    public function isAdmin(): bool
    {
        if (!$this->isLoggedIn) {
            return false;
        }

        if ($this->role === 'admin' || $this->role === 'administrator') {
            return true;
        }

        if (in_array('cms.admin', array_map('strtolower', $this->permissions), true)
            || in_array('kb.admin', array_map('strtolower', $this->permissions), true)) {
            return true;
        }

        return $this->inGroup('admin') || $this->inGroup('administrators');
    }

    /**
     * Whether the subject holds editor or above privileges.
     */
    public function isEditor(): bool
    {
        if (!$this->isLoggedIn) {
            return false;
        }

        if ($this->isAdmin() || $this->role === 'editor') {
            return true;
        }

        return $this->hasPermission('cms.content.publish')
            || $this->hasPermission('kb.content.manage')
            || $this->inGroup('editor')
            || $this->inGroup('editors');
    }

    /**
     * Whether the subject holds author or above privileges.
     */
    public function isAuthor(): bool
    {
        if (!$this->isLoggedIn) {
            return false;
        }

        if ($this->isEditor() || $this->role === 'author') {
            return true;
        }

        return $this->hasPermission('cms.content.edit')
            || $this->hasPermission('kb.content.edit')
            || $this->inGroup('author')
            || $this->inGroup('authors');
    }

    /**
     * Check if the subject has a specific role (case-insensitive).
     */
    public function hasRole(string $role): bool
    {
        $normalized = strtolower(trim($role));
        if ($normalized === '') {
            return false;
        }

        if (strtolower($this->role) === $normalized) {
            return true;
        }

        return in_array($normalized, array_map('strtolower', $this->groups), true);
    }

    /**
     * Check if the subject has a specific permission (case-insensitive).
     * Administrators automatically satisfy all permission queries.
     */
    public function hasPermission(string $permission): bool
    {
        $normalized = strtolower(trim($permission));
        if ($normalized === '') {
            return false;
        }

        if ($this->isAdmin()) {
            return true;
        }

        return in_array($normalized, array_map('strtolower', $this->permissions), true);
    }

    /**
     * Check if the subject belongs to a specific department (case-insensitive).
     */
    public function inDepartment(string $department): bool
    {
        $normalized = strtolower(trim($department));
        if ($normalized === '') {
            return false;
        }

        if ($this->department !== null && strtolower($this->department) === $normalized) {
            return true;
        }

        return in_array($normalized, array_map('strtolower', $this->groups), true);
    }

    /**
     * Check if the subject belongs to a specific team (case-insensitive).
     */
    public function inTeam(string $team): bool
    {
        $normalized = strtolower(trim($team));
        if ($normalized === '') {
            return false;
        }

        if ($this->team !== null && strtolower($this->team) === $normalized) {
            return true;
        }

        return in_array($normalized, array_map('strtolower', $this->groups), true);
    }

    /**
     * Check if the subject is a member of a given group / app role (case-insensitive).
     */
    public function inGroup(string $group): bool
    {
        $normalized = strtolower(trim($group));
        if ($normalized === '') {
            return false;
        }

        return in_array($normalized, array_map('strtolower', $this->groups), true);
    }

    public function hasGroup(string $group): bool
    {
        return $this->inGroup($group);
    }

    public function hasDepartment(string $department): bool
    {
        return $this->inDepartment($department);
    }

    public function hasTeam(string $team): bool
    {
        return $this->inTeam($team);
    }

    /**
     * Export context as array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'email'        => $this->email,
            'role'         => $this->role,
            'department'   => $this->department,
            'team'         => $this->team,
            'groups'       => $this->groups,
            'permissions'  => $this->permissions,
            'is_logged_in' => $this->isLoggedIn,
            'is_admin'     => $this->isAdmin(),
            'is_editor'    => $this->isEditor(),
            'is_author'    => $this->isAuthor(),
            'metadata'     => $this->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<mixed> $list
     * @return list<string>
     */
    private static function normalizeStringList(array $list): array
    {
        $results = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                $trimmed = trim($item);
                if ($trimmed !== '' && !in_array($trimmed, $results, true)) {
                    $results[] = $trimmed;
                }
            } elseif (is_array($item) && isset($item['slug'])) {
                $trimmed = trim((string) $item['slug']);
                if ($trimmed !== '' && !in_array($trimmed, $results, true)) {
                    $results[] = $trimmed;
                }
            } elseif (is_array($item) && isset($item['name'])) {
                $trimmed = trim((string) $item['name']);
                if ($trimmed !== '' && !in_array($trimmed, $results, true)) {
                    $results[] = $trimmed;
                }
            }
        }
        return array_values($results);
    }

    /**
     * @param string $role
     * @return list<string>
     */
    private static function defaultPermissionsForRole(string $role): array
    {
        return match (strtolower(trim($role))) {
            'admin', 'administrator' => [
                'cms.admin',
                'cms.content.publish',
                'cms.content.edit',
                'kb.admin',
                'kb.space.manage',
                'kb.content.manage',
                'kb.content.edit',
            ],
            'editor' => [
                'cms.content.publish',
                'cms.content.edit',
                'kb.space.manage',
                'kb.content.manage',
                'kb.content.edit',
            ],
            'author' => [
                'cms.content.edit',
                'kb.content.edit',
            ],
            default => [],
        };
    }
}
