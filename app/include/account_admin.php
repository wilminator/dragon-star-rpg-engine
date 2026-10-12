<?php
require_once INCLUDE_GAME . 'user_account.php';

// What admin/users.php and admin/user.php do to accounts, with the
// safeguards that keep admins from locking everyone out:
//   - an admin can't put their own account on hold, or take
//     MANAGE_USERS away from themselves, and
//   - MANAGE_USERS can't be taken from the last account holding it.
class AccountAdmin {

    // An account as the admin templates show it.
    public static function view(Database $db, UserAccount $account): array {
        return [
            'userid' => $account->userid,
            'username' => $account->username,
            'email' => $account->email ?? '',
            'on_hold' => $account->on_hold,
            'verified' => $account->is_verified(),
            'created_at' => $account->created_at,
            'last_login_at' => $account->last_login_at,
            'rights' => PermissionGrant::list_for($db, $account->userid),
            'two_factor' => TwoFactor::is_enabled($db, $account),
            'two_factor_app' => TwoFactor::has_app($account),
            'security_keys' => count(SecurityKey::load_for($db, $account->userid)),
        ];
    }

    // Creates an account that can log in straight away (its email, if
    // any, counts as confirmed), holding $permissions. Throws
    // InvalidArgumentException with a readable reason.
    public static function create(Database $db, string $username, string $password, ?string $email, array $permissions = []): UserAccount {
        foreach ($permissions as $permission) {
            if (!array_key_exists($permission, PermissionGrant::ALL)) {
                throw new InvalidArgumentException("Unknown right $permission.");
            }
        }
        // Tables first: on MySQL, creating one inside a transaction
        // would silently commit it.
        UserAccount::ensure_table_exists($db);
        PermissionGrant::ensure_table_exists($db);
        $db->begin_transaction();
        try {
            $account = UserAccount::create($db, $username, $password, $email, verified: true);
            foreach ($permissions as $permission) {
                PermissionGrant::grant($db, $account->userid, $permission);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }
        return $account;
    }

    // Applies an admin's edits to $target, all or nothing. $changes,
    // every key optional:
    //   'username' => string, 'email' => ?string ('' or null: none),
    //   'on_hold' => bool, 'verified' => bool,
    //   'permissions' => string[] (the complete new set),
    //   'password' => string (a new password; '' or null: unchanged),
    //   'two_factor_off' => bool (remove the app, every security key and
    //                       the recovery codes, for a player who lost
    //                       them all)
    // Returns the reasons nothing was changed; [] when it all went in.
    public static function update(Database $db, int $actor_userid, UserAccount $target, array $changes): array {
        UserAccount::ensure_table_exists($db);
        PermissionGrant::ensure_table_exists($db);
        RecoveryCode::ensure_table_exists($db);
        SecurityKey::ensure_table_exists($db);
        $problems = [];
        $is_self = $actor_userid === $target->userid;

        if (array_key_exists('on_hold', $changes) && $changes['on_hold'] && $is_self) {
            $problems[] = "You can't put your own account on hold.";
        }

        $new_permissions = null;
        if (array_key_exists('permissions', $changes)) {
            $new_permissions = array_values(array_unique($changes['permissions']));
            foreach ($new_permissions as $permission) {
                if (!array_key_exists($permission, PermissionGrant::ALL)) {
                    $problems[] = "Unknown right $permission.";
                }
            }
            $had_manage = PermissionGrant::has($db, $target->userid, 'MANAGE_USERS');
            if ($had_manage && !in_array('MANAGE_USERS', $new_permissions, true)) {
                if ($is_self) {
                    $problems[] = "You can't take MANAGE_USERS away from yourself.";
                } elseif (count(PermissionGrant::holders($db, 'MANAGE_USERS')) <= 1) {
                    $problems[] = 'Someone has to keep MANAGE_USERS.';
                }
            }
        }

        $updated = clone $target;
        if (array_key_exists('username', $changes)) {
            $updated->username = (string) $changes['username'];
        }
        if (array_key_exists('email', $changes)) {
            $updated->email = UserAccount::normalize_email($changes['email']);
        }
        if (array_key_exists('on_hold', $changes)) {
            $updated->on_hold = (bool) $changes['on_hold'];
        }
        if (array_key_exists('verified', $changes)) {
            $verified = (bool) $changes['verified'];
            if ($verified && !$updated->is_verified()) {
                $updated->email_verified_at = time();
            } elseif (!$verified) {
                $updated->email_verified_at = null;
            }
        }
        $password = (string) ($changes['password'] ?? '');
        if ($password !== '') {
            try {
                $updated->set_password($password);
            } catch (InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }
        if ($problems !== []) {
            return $problems;
        }

        $two_factor_off = !empty($changes['two_factor_off']) && TwoFactor::is_enabled($db, $updated);
        if ($two_factor_off) {
            $updated->totp_secret = null;
            $updated->totp_last_step = null;
        }

        $db->begin_transaction();
        try {
            $updated->save($db);
            if ($two_factor_off) {
                SecurityKey::delete_all_for($db, $target->userid);
                RecoveryCode::delete_all($db, $target->userid);
            }
            if ($new_permissions !== null) {
                foreach (PermissionGrant::list_for($db, $target->userid) as $held) {
                    if (!in_array($held, $new_permissions, true)) {
                        PermissionGrant::revoke($db, $target->userid, $held);
                    }
                }
                foreach ($new_permissions as $permission) {
                    PermissionGrant::grant($db, $target->userid, $permission);
                }
            }
            $db->commit();
        } catch (InvalidArgumentException $e) {
            $db->rollback();
            return [$e->getMessage()];
        } catch (\Throwable $e) {
            $db->rollback();
            throw $e;
        }

        foreach (get_object_vars($updated) as $name => $value) {
            $target->$name = $value;
        }
        return [];
    }
}
