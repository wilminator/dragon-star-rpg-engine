<?php

use PHPUnit\Framework\TestCase;

/**
 * Pages the hub replaced stay retired: the old after-fight page is gone
 * and nothing sends players to it, the old pages live outside the web
 * root, and the overworld's map calls are off the allow-list.
 */
class RetiredPagesTest extends TestCase {

    private function app_sources(): array {
        $files = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(INCLUDE_GAME) . '/', FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if (preg_match('/\.(php|js|twig)$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    public function test_the_after_fight_page_is_gone_and_nothing_sends_players_there() {
        $app = dirname(INCLUDE_GAME) . '/';
        $this->assertFileDoesNotExist($app . 'proc_fight.php', 'fights settle themselves; a second visit paid out again');
        foreach ($this->app_sources() as $path) {
            $this->assertStringNotContainsString('proc_fight', file_get_contents($path), $path);
        }
    }

    public function test_old_pages_and_scripts_are_not_in_the_web_root() {
        $app = dirname(INCLUDE_GAME) . '/';
        $this->assertDirectoryDoesNotExist($app . 'legacy', 'the NAS image copies app/ whole');
        $this->assertFileDoesNotExist($app . 'export.php');
        $this->assertFileDoesNotExist($app . 'functions.php', 'display_team() is include/team_display.php');
    }

    public function test_the_editors_no_longer_take_animation_data_from_the_session() {
        $app = dirname(INCLUDE_GAME) . '/';
        $this->assertFileDoesNotExist($app . 'admin/get_animations.php', 'it saved what the browser posted and the editors printed it raw');
        foreach ($this->app_sources() as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString("_SESSION['animationjs']", $source, $path);
            $this->assertStringNotContainsString("_SESSION['animations']", $source, $path);
        }
    }
}
