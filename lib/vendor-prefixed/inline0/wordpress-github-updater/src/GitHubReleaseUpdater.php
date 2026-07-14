<?php

declare (strict_types=1);
namespace Onumia\Lib\Inline0\WordPressGitHubUpdater;

use Closure;
use Throwable;
/**
 * Registers a GitHub Releases source with the normal WordPress update UI.
 */
final class GitHubReleaseUpdater
{
    private const PUC_FACTORY_SUFFIX = 'Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5\PucFactory';
    private const PUC_GITHUB_API_SUFFIX = 'Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi';
    private const PUC_REQUIRE_RELEASE_ASSETS = 2;
    private const PUC_STRATEGY_LATEST_RELEASE = 'latest_release';
    private ?object $checker = null;
    private readonly ReleaseSignatureVerifier $verifier;
    /**
     * @var Closure(string,string,string):object|null
     */
    private ?Closure $checker_factory_for_tests = null;
    public function __construct(private readonly UpdaterConfig $config)
    {
        $this->verifier = new ReleaseSignatureVerifier($config, $this);
    }
    public function register(): ?object
    {
        if (null !== $this->checker) {
            return $this->checker;
        }
        if ($this->config->disabled() || null === $this->plugin_basename()) {
            return null;
        }
        $checker = $this->build_checker();
        if (null === $checker || !$this->enable_release_assets($checker) || !$this->require_release_update_strategy($checker)) {
            return null;
        }
        $this->apply_authentication($checker);
        $this->add_result_metadata($checker);
        $this->verifier->register();
        $this->checker = $checker;
        return $this->checker;
    }
    public function registered_checker(): ?object
    {
        return $this->checker;
    }
    public function verifier(): ReleaseSignatureVerifier
    {
        return $this->verifier;
    }
    public function repository_url(): string
    {
        return $this->config->resolved_repository_url();
    }
    public function token(): string
    {
        return $this->config->token();
    }
    public function asset_regex(): string
    {
        return $this->config->resolved_asset_regex();
    }
    public function plugin_basename(): ?string
    {
        if ('' === trim($this->config->plugin_file)) {
            return null;
        }
        return $this->config->plugin_basename();
    }
    /**
     * @param callable(string,string,string):object|null $factory Checker factory.
     */
    public function set_checker_factory_for_tests(?callable $factory): void
    {
        $this->checker_factory_for_tests = null === $factory ? null : Closure::fromCallable($factory);
    }
    public function reset_for_tests(): void
    {
        $this->checker = null;
        $this->checker_factory_for_tests = null;
        $this->verifier->reset_for_tests();
    }
    private function build_checker(): ?object
    {
        $repository_url = $this->repository_url();
        if (null !== $this->checker_factory_for_tests) {
            $checker = ($this->checker_factory_for_tests)($repository_url, $this->config->plugin_file, $this->config->plugin_slug);
            return is_object($checker) ? $checker : null;
        }
        try {
            $this->config->load_puc();
            $factory = $this->config->puc_class(self::PUC_FACTORY_SUFFIX);
            if (!$this->has_default_factory_runtime($factory)) {
                return null;
            }
            $this->prime_factory_versions($factory);
            if (!is_callable(array($factory, 'buildUpdateChecker'))) {
                return null;
            }
            $checker = call_user_func(array($factory, 'buildUpdateChecker'), $repository_url, $this->config->plugin_file, $this->config->plugin_slug);
            return is_object($checker) ? $checker : null;
        } catch (Throwable) {
            return null;
        }
    }
    private function has_default_factory_runtime(string $factory): bool
    {
        if (!class_exists($factory)) {
            return \false;
        }
        foreach (array('ABSPATH', 'WP_DEBUG', 'WP_PLUGIN_DIR', 'WPMU_PLUGIN_DIR') as $constant) {
            if (!defined($constant)) {
                return \false;
            }
        }
        return \true;
    }
    private function prime_factory_versions(string $factory): void
    {
        if (!is_callable(array($factory, 'addVersion'))) {
            return;
        }
        // Keep general PUC keys dynamic so dependency scopers do not prefix them.
        // phpcs:disable Generic.Strings.UnnecessaryStringConcat.Found
        $classes = array('Plugin' . '\UpdateChecker' => 'Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5p7\Plugin\UpdateChecker', 'Theme' . '\UpdateChecker' => 'Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5p7\Theme\UpdateChecker', 'Vcs' . '\PluginUpdateChecker' => 'Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\PluginUpdateChecker', 'Vcs' . '\ThemeUpdateChecker' => 'Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\ThemeUpdateChecker', 'GitHubApi' => self::PUC_GITHUB_API_SUFFIX, 'BitBucketApi' => 'Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\BitBucketApi', 'GitLabApi' => 'Onumia\Lib\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitLabApi');
        // phpcs:enable Generic.Strings.UnnecessaryStringConcat.Found
        foreach ($classes as $general_class => $class_suffix) {
            $class = $this->config->puc_class($class_suffix);
            if (class_exists($class)) {
                call_user_func(array($factory, 'addVersion'), $general_class, $class, '5.7');
            }
        }
    }
    private function enable_release_assets(object $checker): bool
    {
        if (!method_exists($checker, 'getVcsApi')) {
            return \false;
        }
        $api = $checker->getVcsApi();
        if (!is_object($api) || !method_exists($api, 'enableReleaseAssets')) {
            return \false;
        }
        $api->enableReleaseAssets($this->asset_regex(), self::PUC_REQUIRE_RELEASE_ASSETS);
        return \true;
    }
    private function require_release_update_strategy(object $checker): bool
    {
        if (!method_exists($checker, 'addFilter')) {
            return \false;
        }
        $checker->addFilter('vcs_update_detection_strategies', static function (array $strategies): array {
            return isset($strategies[self::PUC_STRATEGY_LATEST_RELEASE]) ? array(self::PUC_STRATEGY_LATEST_RELEASE => $strategies[self::PUC_STRATEGY_LATEST_RELEASE]) : array();
        }, 10, 2);
        return \true;
    }
    private function apply_authentication(object $checker): void
    {
        $token = $this->token();
        if ('' !== $token && method_exists($checker, 'setAuthentication')) {
            $checker->setAuthentication($token);
        }
    }
    private function add_result_metadata(object $checker): void
    {
        if (!method_exists($checker, 'addResultFilter')) {
            return;
        }
        $checker->addResultFilter(function (mixed $info): mixed {
            if (!is_object($info)) {
                return $info;
            }
            if (property_exists($info, 'version') && is_scalar($info->version)) {
                $info->version = trim((string) $info->version);
            }
            $icons = array_filter(array('svg' => $this->config->icon_url('icon.svg'), 'default' => $this->config->icon_url('icon.png')), 'is_string');
            if (array() !== $icons && ($info instanceof \stdClass || property_exists($info, 'icons'))) {
                $info->icons = $icons;
            }
            return $info;
        });
    }
}
