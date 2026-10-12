<?php

/**
 * Covers accounts on the portable Database layer: UserAccount (users),
 * PermissionGrant (permissions), and the rules AccountAdmin enforces
 * for the admin account pages.
 */
trait UserAccountPortabilityTests {

    private function reset_account_tables(): void {
        $this->reset_tables(UserAccount::class, PermissionGrant::class);
    }

    // --- creating and finding accounts ---

    public function test_create_then_load_by_id_name_and_email() {
        $this->reset_account_tables();
        $created = UserAccount::create($this->db, 'Alice', 'correct horse', 'Alice@Example.COM');

        $loaded = UserAccount::load($this->db, $created->userid);
        $this->assertSame('Alice', $loaded->username);
        $this->assertSame('alice@example.com', $loaded->email, 'emails are stored lowercased');
        $this->assertFalse($loaded->on_hold);
        $this->assertFalse($loaded->is_verified(), 'public accounts confirm by email');
        $this->assertEqualsWithDelta(time(), $loaded->created_at, 5);
        $this->assertSame($created->userid, UserAccount::load_by_username($this->db, 'aLiCe')->userid, 'any case');
        $this->assertSame($created->userid, UserAccount::load_by_email($this->db, ' ALICE@example.com ')->userid);
        $this->assertNull(UserAccount::load_by_username($this->db, 'nobody'));
    }

    public function test_an_account_can_start_out_verified() {
        $this->reset_account_tables();
        $account = UserAccount::create($this->db, 'Bob', 'correct horse', null, verified: true);
        $this->assertTrue(UserAccount::load($this->db, $account->userid)->is_verified());
    }

    public function test_usernames_are_unique_regardless_of_case() {
        $this->reset_account_tables();
        UserAccount::create($this->db, 'Alice', 'correct horse');

        $this->expectExceptionMessage('That username is taken.');
        UserAccount::create($this->db, 'ALICE', 'correct horse');
    }

    public function test_emails_are_unique_regardless_of_case() {
        $this->reset_account_tables();
        UserAccount::create($this->db, 'Alice', 'correct horse', 'a@example.com');

        $this->expectExceptionMessage('That email address already has an account.');
        UserAccount::create($this->db, 'Bob', 'correct horse', 'A@EXAMPLE.com');
    }

    public function test_several_accounts_may_have_no_email() {
        $this->reset_account_tables();
        UserAccount::create($this->db, 'Alice', 'correct horse');
        UserAccount::create($this->db, 'Bob', 'correct horse', '');
        $this->assertSame(2, UserAccount::count_all($this->db));
    }

    public function test_bad_usernames_passwords_and_emails_are_refused() {
        $this->reset_account_tables();
        foreach ([['ab', 'correct horse', null], ['has space', 'correct horse', null], [str_repeat('a', 21), 'correct horse', null],
                  ['Alice', 'short', null], ['Alice', 'correct horse', 'not-an-email']] as [$name, $password, $email]) {
            try {
                UserAccount::create($this->db, $name, $password, $email);
                $this->fail("accepted $name / $password / $email");
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, UserAccount::count_all($this->db));
    }

    public function test_passwords_are_hashed_and_checked() {
        $this->reset_account_tables();
        $account = UserAccount::create($this->db, 'Alice', 'correct horse');

        $this->assertNotSame('correct horse', $account->password_hash);
        $this->assertTrue($account->password_matches('correct horse'));
        $this->assertFalse($account->password_matches('wrong horse'));
    }

    public function test_an_outdated_hash_is_upgraded_when_the_password_matches() {
        $this->reset_account_tables();
        $account = UserAccount::create($this->db, 'Alice', 'correct horse');
        $account->password_hash = password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertTrue($account->password_matches('correct horse'));
        $this->assertFalse(password_needs_rehash($account->password_hash, UserAccount::password_algorithm()));
    }

    public function test_save_writes_every_field_back() {
        $this->reset_account_tables();
        $account = UserAccount::create($this->db, 'Alice', 'correct horse');
        $account->username = 'Alicia';
        $account->email = 'NEW@example.com';
        $account->on_hold = true;
        $account->email_verified_at = 1234;
        $account->last_login_at = 5678;
        $account->set_password('another horse');
        $account->save($this->db);

        $loaded = UserAccount::load($this->db, $account->userid);
        $this->assertSame('Alicia', $loaded->username);
        $this->assertSame('new@example.com', $loaded->email);
        $this->assertTrue($loaded->on_hold);
        $this->assertSame(1234, $loaded->email_verified_at);
        $this->assertSame(5678, $loaded->last_login_at);
        $this->assertTrue($loaded->password_matches('another horse'));
    }

    public function test_save_refuses_another_accounts_username_or_email() {
        $this->reset_account_tables();
        UserAccount::create($this->db, 'Alice', 'correct horse', 'a@example.com');
        $bob = UserAccount::create($this->db, 'Bob', 'correct horse', 'b@example.com');

        $bob->username = 'alice';
        try {
            $bob->save($this->db);
            $this->fail('took a username');
        } catch (InvalidArgumentException $e) {
        }
        $bob->username = 'Bob';
        $bob->email = 'a@example.com';
        $this->expectExceptionMessage('That email address already has an account.');
        $bob->save($this->db);
    }

    // --- searching ---

    public function test_search_by_part_of_a_username_or_email_or_by_id() {
        $this->reset_account_tables();
        $alice = UserAccount::create($this->db, 'Alice', 'correct horse', 'alice@wonder.land');
        $bob = UserAccount::create($this->db, 'Bob', 'correct horse', 'bob@builder.com');

        $names = fn($accounts) => array_map(fn($a) => $a->username, $accounts);
        $this->assertSame(['Alice'], $names(UserAccount::search($this->db, 'LIC')));
        $this->assertSame(['Bob'], $names(UserAccount::search($this->db, 'builder')));
        $this->assertSame(['Bob'], $names(UserAccount::search($this->db, (string) $bob->userid)));
        $this->assertSame(['Alice', 'Bob'], $names(UserAccount::search($this->db)));
    }

    public function test_search_filters_on_hold_unconfirmed_and_rights() {
        $this->reset_account_tables();
        $held = UserAccount::create($this->db, 'Held', 'correct horse', null, verified: true);
        $held->on_hold = true;
        $held->save($this->db);
        UserAccount::create($this->db, 'Fresh', 'correct horse');
        $admin = UserAccount::create($this->db, 'Boss', 'correct horse', null, verified: true);
        PermissionGrant::grant($this->db, $admin->userid, 'MANAGE_USERS');

        $names = fn($accounts) => array_map(fn($a) => $a->username, $accounts);
        $this->assertSame(['Held'], $names(UserAccount::search($this->db, '', ['on_hold' => true])));
        $this->assertSame(['Fresh'], $names(UserAccount::search($this->db, '', ['unverified' => true])));
        $this->assertSame(['Boss'], $names(UserAccount::search($this->db, '', ['permission' => 'MANAGE_USERS'])));
    }

    // --- rights ---

    public function test_granting_and_revoking_rights() {
        $this->reset_account_tables();
        $account = UserAccount::create($this->db, 'Alice', 'correct horse');

        PermissionGrant::grant($this->db, $account->userid, 'SPECTATE_FIGHTS');
        PermissionGrant::grant($this->db, $account->userid, 'EDIT_ITEM');
        PermissionGrant::grant($this->db, $account->userid, 'EDIT_ITEM'); // twice changes nothing
        $this->assertTrue(PermissionGrant::has($this->db, $account->userid, 'SPECTATE_FIGHTS'));
        $this->assertSame(['EDIT_ITEM', 'SPECTATE_FIGHTS'], PermissionGrant::list_for($this->db, $account->userid));
        $this->assertSame([$account->userid], PermissionGrant::holders($this->db, 'EDIT_ITEM'));

        PermissionGrant::revoke($this->db, $account->userid, 'EDIT_ITEM');
        $this->assertSame(['SPECTATE_FIGHTS'], PermissionGrant::list_for($this->db, $account->userid));
    }

    public function test_unknown_rights_are_refused() {
        $this->reset_account_tables();
        $this->expectException(InvalidArgumentException::class);
        PermissionGrant::grant($this->db, 1, 'EDIT_ITEMS');
    }

    // --- AccountAdmin ---

    private function manager(string $name = 'Boss'): UserAccount {
        return AccountAdmin::create($this->db, $name, 'correct horse', null, ['MANAGE_USERS']);
    }

    public function test_admin_created_accounts_can_log_in_at_once_and_get_their_rights() {
        $this->reset_account_tables();
        $account = AccountAdmin::create($this->db, 'Helper', 'correct horse', 'h@example.com', ['SPECTATE_FIGHTS', 'EDIT_JOB']);

        $this->assertTrue(UserAccount::load($this->db, $account->userid)->is_verified());
        $this->assertSame(['EDIT_JOB', 'SPECTATE_FIGHTS'], PermissionGrant::list_for($this->db, $account->userid));
    }

    public function test_admin_create_with_an_unknown_right_creates_nothing() {
        $this->reset_account_tables();
        try {
            AccountAdmin::create($this->db, 'Helper', 'correct horse', null, ['NOPE']);
            $this->fail('accepted an unknown right');
        } catch (InvalidArgumentException $e) {
        }
        $this->assertSame(0, UserAccount::count_all($this->db));
    }

    public function test_an_admin_update_changes_everything_asked() {
        $this->reset_account_tables();
        $boss = $this->manager();
        $target = UserAccount::create($this->db, 'Target', 'correct horse');
        PermissionGrant::grant($this->db, $target->userid, 'EDIT_ITEM');

        $problems = AccountAdmin::update($this->db, $boss->userid, $target, [
            'username' => 'Renamed', 'email' => 'T@example.com', 'on_hold' => true, 'verified' => true,
            'permissions' => ['SPECTATE_FIGHTS'], 'password' => 'brand new horse',
        ]);

        $this->assertSame([], $problems);
        $loaded = UserAccount::load($this->db, $target->userid);
        $this->assertSame('Renamed', $loaded->username);
        $this->assertSame('t@example.com', $loaded->email);
        $this->assertTrue($loaded->on_hold);
        $this->assertTrue($loaded->is_verified());
        $this->assertTrue($loaded->password_matches('brand new horse'));
        $this->assertSame(['SPECTATE_FIGHTS'], PermissionGrant::list_for($this->db, $target->userid));
        $this->assertSame('Renamed', $target->username, 'the passed account reflects the change');
    }

    public function test_a_blank_password_leaves_it_unchanged() {
        $this->reset_account_tables();
        $boss = $this->manager();
        $target = UserAccount::create($this->db, 'Target', 'correct horse');

        AccountAdmin::update($this->db, $boss->userid, $target, ['password' => '']);

        $this->assertTrue(UserAccount::load($this->db, $target->userid)->password_matches('correct horse'));
    }

    public function test_an_admin_cannot_put_themselves_on_hold() {
        $this->reset_account_tables();
        $boss = $this->manager();

        $problems = AccountAdmin::update($this->db, $boss->userid, $boss, ['on_hold' => true]);

        $this->assertNotSame([], $problems);
        $this->assertFalse(UserAccount::load($this->db, $boss->userid)->on_hold);
    }

    public function test_an_admin_cannot_take_manage_users_from_themselves() {
        $this->reset_account_tables();
        $boss = $this->manager();
        $this->manager('Other');

        $problems = AccountAdmin::update($this->db, $boss->userid, $boss, ['permissions' => ['SPECTATE_FIGHTS']]);

        $this->assertNotSame([], $problems);
        $this->assertSame(['MANAGE_USERS'], PermissionGrant::list_for($this->db, $boss->userid));
    }

    public function test_the_last_manage_users_holder_keeps_it() {
        $this->reset_account_tables();
        $only = $this->manager('Only');
        $someone = UserAccount::create($this->db, 'Someone', 'correct horse');

        $problems = AccountAdmin::update($this->db, $someone->userid, $only, ['permissions' => []]);

        $this->assertSame(['Someone has to keep MANAGE_USERS.'], $problems);
        $this->assertSame(['MANAGE_USERS'], PermissionGrant::list_for($this->db, $only->userid));
    }

    public function test_manage_users_can_be_taken_from_another_admin_while_someone_keeps_it() {
        $this->reset_account_tables();
        $boss = $this->manager();
        $other = $this->manager('Other');

        $this->assertSame([], AccountAdmin::update($this->db, $boss->userid, $other, ['permissions' => []]));
        $this->assertSame([], PermissionGrant::list_for($this->db, $other->userid));
    }

    public function test_an_update_with_any_problem_changes_nothing() {
        $this->reset_account_tables();
        $boss = $this->manager();
        UserAccount::create($this->db, 'Taken', 'correct horse');
        $target = UserAccount::create($this->db, 'Target', 'correct horse');

        $problems = AccountAdmin::update($this->db, $boss->userid, $target, [
            'username' => 'taken', 'permissions' => ['SPECTATE_FIGHTS'], 'on_hold' => true,
        ]);

        $this->assertSame(['That username is taken.'], $problems);
        $loaded = UserAccount::load($this->db, $target->userid);
        $this->assertSame('Target', $loaded->username);
        $this->assertFalse($loaded->on_hold);
        $this->assertSame([], PermissionGrant::list_for($this->db, $target->userid));
        $this->assertSame('Target', $target->username);
    }

    public function test_unconfirming_an_email() {
        $this->reset_account_tables();
        $boss = $this->manager();
        $target = UserAccount::create($this->db, 'Target', 'correct horse', null, verified: true);

        AccountAdmin::update($this->db, $boss->userid, $target, ['verified' => false]);

        $this->assertFalse(UserAccount::load($this->db, $target->userid)->is_verified());
    }
}
