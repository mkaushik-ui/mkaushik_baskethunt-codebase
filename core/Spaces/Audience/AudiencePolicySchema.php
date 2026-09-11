<?php
declare(strict_types=1);

namespace SOI\Core\Spaces\Audience;

/**
 * AudiencePolicySchema
 *
 * Schema constants, default rules, validation rules, and structural validators
 * for Knowledge Center Space & Document Audience Policies in accordance with [WD-02].
 */
final class AudiencePolicySchema
{
    public const SCHEMA_VERSION = '1.0';

    // Visibility Enums (SpaceSchema Parity)
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_AUTHENTICATED = 'authenticated';
    public const VISIBILITY_INTERNAL = 'internal';
    public const VISIBILITY_RESTRICTED = 'restricted';
    public const VISIBILITY_PRIVATE = 'private';

    public const VALID_VISIBILITIES = [
        self::VISIBILITY_PUBLIC,
        self::VISIBILITY_AUTHENTICATED,
        self::VISIBILITY_INTERNAL,
        self::VISIBILITY_RESTRICTED,
        self::VISIBILITY_PRIVATE,
    ];

    // Policy Rule Modes
    public const MODE_ANY = 'any'; // Subject needs to match ANY rule (default)
    public const MODE_ALL = 'all'; // Subject must match ALL specified rule criteria

    public const VALID_MODES = [
        self::MODE_ANY,
        self::MODE_ALL,
    ];

    // Inheritance Modes
    public const INHERITANCE_INHERIT = 'inherit';
    public const INHERITANCE_OVERRIDE = 'override';
    public const INHERITANCE_STRICT = 'strict';

    public const VALID_INHERITANCE = [
        self::INHERITANCE_INHERIT,
        self::INHERITANCE_OVERRIDE,
        self::INHERITANCE_STRICT,
    ];

    // Canonical Policy Schema Keys
    public const KEY_SCHEMA_VERSION = 'schema_version';
    public const KEY_VISIBILITY = 'visibility';
    public const KEY_MODE = 'mode';
    public const KEY_INHERITANCE = 'inheritance';
    public const KEY_DEPARTMENTS = 'departments';
    public const KEY_TEAMS = 'teams';
    public const KEY_GROUPS = 'groups';
    public const KEY_ALLOWED_USERS = 'allowed_users';
    public const KEY_USERS = 'users'; // Alias for allowed_users
    public const KEY_SPACE_OWNERS = 'space_owners';
    public const KEY_ROLES = 'roles';
    public const KEY_PERMISSIONS = 'permissions';
    public const KEY_EXCLUDED_DEPARTMENTS = 'excluded_departments';
    public const KEY_EXCLUDED_TEAMS = 'excluded_teams';
    public const KEY_EXCLUDED_USERS = 'excluded_users';
    public const KEY_MANAGER_ROLES = 'manager_roles';
    public const KEY_MANAGER_GROUPS = 'manager_groups';
    public const KEY_MANAGER_USERS = 'manager_users';
    public const KEY_NOTES = 'notes';

    /**
     * Default canonical policy representation.
     *
     * @return array<string, mixed>
     */
    public static function defaultPolicy(): array
    {
        return [
            self::KEY_SCHEMA_VERSION       => self::SCHEMA_VERSION,
            self::KEY_VISIBILITY           => self::VISIBILITY_PUBLIC,
            self::KEY_MODE                 => self::MODE_ANY,
            self::KEY_INHERITANCE          => self::INHERITANCE_INHERIT,
            self::KEY_DEPARTMENTS          => [],
            self::KEY_TEAMS                => [],
            self::KEY_GROUPS               => [],
            self::KEY_ALLOWED_USERS        => [],
            self::KEY_USERS                => [],
            self::KEY_SPACE_OWNERS         => [],
            self::KEY_ROLES                => [],
            self::KEY_PERMISSIONS          => [],
            self::KEY_EXCLUDED_DEPARTMENTS => [],
            self::KEY_EXCLUDED_TEAMS       => [],
            self::KEY_EXCLUDED_USERS       => [],
            self::KEY_MANAGER_ROLES        => ['admin', 'editor'],
            self::KEY_MANAGER_GROUPS       => [],
            self::KEY_MANAGER_USERS        => [],
        ];
    }

    /**
     * Validate raw policy structure, types, and supported keys.
     *
     * @param array<string, mixed>|string|null $raw
     * @return array{valid: bool, errors: list<string>, normalized: array<string, mixed>|null}
     */
    public static function validate(array|string|null $raw): array
    {
        $errors = [];

        if ($raw === null || $raw === '') {
            return [
                'valid'      => true,
                'errors'     => [],
                'normalized' => self::defaultPolicy(),
            ];
        }

        $parsed = $raw;
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '' || $trimmed === '{}' || $trimmed === '[]') {
                return [
                    'valid'      => true,
                    'errors'     => [],
                    'normalized' => self::defaultPolicy(),
                ];
            }
            $decoded = json_decode($trimmed, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                return [
                    'valid'      => false,
                    'errors'     => ['Policy must be valid JSON: ' . json_last_error_msg()],
                    'normalized' => null,
                ];
            }
            $parsed = $decoded;
        }

        if (!is_array($parsed)) {
            return [
                'valid'      => false,
                'errors'     => ['Policy root must be an associative array or object.'],
                'normalized' => null,
            ];
        }

        // Validate Visibility if specified
        if (isset($parsed[self::KEY_VISIBILITY])) {
            $vis = strtolower(trim((string) $parsed[self::KEY_VISIBILITY]));
            if (!in_array($vis, self::VALID_VISIBILITIES, true)) {
                $errors[] = "Invalid policy visibility '{$vis}'. Allowed: " . implode(', ', self::VALID_VISIBILITIES);
            }
        }

        // Validate Mode
        $mode = $parsed[self::KEY_MODE] ?? self::MODE_ANY;
        if (!is_string($mode) || !in_array(strtolower(trim($mode)), self::VALID_MODES, true)) {
            $errors[] = "Invalid policy mode '{$mode}'. Allowed modes: " . implode(', ', self::VALID_MODES);
        }

        // Validate Inheritance
        if (isset($parsed[self::KEY_INHERITANCE])) {
            $inh = strtolower(trim((string) $parsed[self::KEY_INHERITANCE]));
            if (!in_array($inh, self::VALID_INHERITANCE, true)) {
                $errors[] = "Invalid policy inheritance '{$inh}'. Allowed: " . implode(', ', self::VALID_INHERITANCE);
            }
        }

        // Validate Array Collections
        $listKeys = [
            self::KEY_DEPARTMENTS,
            self::KEY_TEAMS,
            self::KEY_GROUPS,
            self::KEY_ALLOWED_USERS,
            self::KEY_USERS,
            self::KEY_SPACE_OWNERS,
            self::KEY_ROLES,
            self::KEY_PERMISSIONS,
            self::KEY_EXCLUDED_DEPARTMENTS,
            self::KEY_EXCLUDED_TEAMS,
            self::KEY_EXCLUDED_USERS,
            self::KEY_MANAGER_ROLES,
            self::KEY_MANAGER_GROUPS,
            self::KEY_MANAGER_USERS,
        ];

        foreach ($listKeys as $key) {
            if (isset($parsed[$key]) && !is_array($parsed[$key])) {
                $errors[] = "Policy field '{$key}' must be an array of values.";
            }
        }

        if (!empty($errors)) {
            return [
                'valid'      => false,
                'errors'     => $errors,
                'normalized' => null,
            ];
        }

        return [
            'valid'      => true,
            'errors'     => [],
            'normalized' => self::normalize($parsed),
        ];
    }

    /**
     * Normalize policy input into a strict canonical array.
     *
     * @param array<string, mixed>|string|null $raw
     * @param string|null $visibility
     * @return array<string, mixed>
     */
    public static function normalize(array|string|null $raw, ?string $visibility = null): array
    {
        if ($raw === null || $raw === '') {
            return self::defaultPolicy($visibility);
        }

        $data = $raw;
        if (is_string($raw)) {
            $decoded = json_decode(trim($raw), true);
            $data = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($data)) {
            return self::defaultPolicy($visibility);
        }

        $defaults = self::defaultPolicy($visibility);
        $mode = strtolower(trim((string) ($data[self::KEY_MODE] ?? $defaults[self::KEY_MODE])));
        if (!in_array($mode, self::VALID_MODES, true)) {
            $mode = self::MODE_ANY;
        }

        $vis = strtolower(trim((string) ($data[self::KEY_VISIBILITY] ?? $defaults[self::KEY_VISIBILITY])));
        if ($visibility !== null && in_array(strtolower(trim($visibility)), self::VALID_VISIBILITIES, true)) {
            $vis = strtolower(trim($visibility));
        } elseif (!in_array($vis, self::VALID_VISIBILITIES, true)) {
            $vis = self::VISIBILITY_PUBLIC;
        }

        $inh = strtolower(trim((string) ($data[self::KEY_INHERITANCE] ?? $defaults[self::KEY_INHERITANCE])));
        if (!in_array($inh, self::VALID_INHERITANCE, true)) {
            $inh = self::INHERITANCE_INHERIT;
        }

        // Merge allowed_users and users
        $rawAllowedUsers = array_merge(
            (array) ($data[self::KEY_ALLOWED_USERS] ?? []),
            (array) ($data[self::KEY_USERS] ?? [])
        );
        $allowedUsers = self::cleanUserList($rawAllowedUsers);

        return [
            self::KEY_SCHEMA_VERSION       => (string) ($data[self::KEY_SCHEMA_VERSION] ?? self::SCHEMA_VERSION),
            self::KEY_VISIBILITY           => $vis,
            self::KEY_MODE                 => $mode,
            self::KEY_INHERITANCE          => $inh,
            self::KEY_DEPARTMENTS          => self::cleanStringList($data[self::KEY_DEPARTMENTS] ?? []),
            self::KEY_TEAMS                => self::cleanStringList($data[self::KEY_TEAMS] ?? []),
            self::KEY_GROUPS               => self::cleanStringList($data[self::KEY_GROUPS] ?? []),
            self::KEY_ALLOWED_USERS        => $allowedUsers,
            self::KEY_USERS                => $allowedUsers,
            self::KEY_SPACE_OWNERS         => self::cleanUserList($data[self::KEY_SPACE_OWNERS] ?? []),
            self::KEY_ROLES                => self::cleanStringList($data[self::KEY_ROLES] ?? []),
            self::KEY_PERMISSIONS          => self::cleanStringList($data[self::KEY_PERMISSIONS] ?? []),
            self::KEY_EXCLUDED_DEPARTMENTS => self::cleanStringList($data[self::KEY_EXCLUDED_DEPARTMENTS] ?? []),
            self::KEY_EXCLUDED_TEAMS       => self::cleanStringList($data[self::KEY_EXCLUDED_TEAMS] ?? []),
            self::KEY_EXCLUDED_USERS       => self::cleanUserList($data[self::KEY_EXCLUDED_USERS] ?? []),
            self::KEY_MANAGER_ROLES        => self::cleanStringList($data[self::KEY_MANAGER_ROLES] ?? $defaults[self::KEY_MANAGER_ROLES]),
            self::KEY_MANAGER_GROUPS       => self::cleanStringList($data[self::KEY_MANAGER_GROUPS] ?? []),
            self::KEY_MANAGER_USERS        => self::cleanUserList($data[self::KEY_MANAGER_USERS] ?? []),
        ];
    }

    /**
     * @param mixed $list
     * @return list<string>
     */
    private static function cleanStringList(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $cleaned = [];
        foreach ($list as $item) {
            if (is_string($item) || is_numeric($item)) {
                $str = trim((string) $item);
                if ($str !== '' && !in_array($str, $cleaned, true)) {
                    $cleaned[] = $str;
                }
            }
        }
        return array_values($cleaned);
    }

    /**
     * @param mixed $list
     * @return list<int|string>
     */
    private static function cleanUserList(mixed $list): array
    {
        if (!is_array($list)) {
            return [];
        }

        $cleaned = [];
        foreach ($list as $item) {
            if (is_int($item) && $item > 0) {
                if (!in_array($item, $cleaned, true)) {
                    $cleaned[] = $item;
                }
            } elseif (is_string($item)) {
                $str = trim($item);
                if ($str !== '') {
                    if (ctype_digit($str) && (int) $str > 0) {
                        $intVal = (int) $str;
                        if (!in_array($intVal, $cleaned, true)) {
                            $cleaned[] = $intVal;
                        }
                    } else {
                        $lower = strtolower($str);
                        if (!in_array($lower, $cleaned, true)) {
                            $cleaned[] = $lower;
                        }
                    }
                }
            }
        }
        return array_values($cleaned);
    }
}
