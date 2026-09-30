<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor;

use Zactonz\CronDoctor\Doctor\Checkup;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\InspectionBuilder;
use Zactonz\CronDoctor\Job\JobManager;
use Zactonz\CronDoctor\Job\JobStore;
use Zactonz\CronDoctor\Job\Payload;
use Zactonz\CronDoctor\Job\RunHistory;
use Zactonz\CronDoctor\Job\Settings;
use Zactonz\CronDoctor\Platform\CpanelApi;
use Zactonz\CronDoctor\Platform\CrontabGateway;
use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Platform\LiveCpanelApi;
use Zactonz\CronDoctor\Platform\PhpBinaries;
use Zactonz\CronDoctor\Security\AuditLog;
use Zactonz\CronDoctor\Security\Token;
use Zactonz\CronDoctor\Support\Clock;
use Zactonz\CronDoctor\View\Presenter;

final class Application
{
    private Environment $environment;

    private CpanelApi $api;

    private CrontabGateway $gateway;

    private JobStore $store;

    private RunHistory $history;

    private PhpBinaries $phpBinaries;

    private Payload $payload;

    private JobManager $manager;

    private Clock $clock;

    private Token $token;

    private AuditLog $audit;

    private Settings $settings;

    private ?Inspection $inspection = null;

    private ?array $findings = null;

    public function __construct(
        Environment $environment,
        CpanelApi $api,
        string $pluginDirectory,
        string $session,
        ?Clock $clock = null,
        string $phpRoot = ''
    ) {
        $this->environment = $environment;
        $this->api = $api;
        $this->clock = $clock ?: new Clock();
        $this->gateway = new CrontabGateway($environment, $api);
        $this->store = new JobStore($environment);
        $this->history = new RunHistory($environment);
        $this->phpBinaries = new PhpBinaries($api, $phpRoot);
        $this->payload = new Payload($environment, $pluginDirectory);
        $this->manager = new JobManager($environment, $this->gateway, $this->store, $this->payload);
        $this->token = new Token($environment, $session);
        $this->audit = new AuditLog($environment);
        $this->settings = new Settings($environment);
    }

    public static function boot(
        string $pluginDirectory,
        array $server,
        $cpanel = null,
        ?string $crontabBinary = null,
        string $phpRoot = ''
    ): self {
        return new self(
            Environment::discover(null, $crontabBinary),
            new LiveCpanelApi($cpanel),
            $pluginDirectory,
            Token::sessionFromRequest($server),
            null,
            $phpRoot
        );
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function gateway(): CrontabGateway
    {
        return $this->gateway;
    }

    public function store(): JobStore
    {
        return $this->store;
    }

    public function history(): RunHistory
    {
        return $this->history;
    }

    public function phpBinaries(): PhpBinaries
    {
        return $this->phpBinaries;
    }

    public function payload(): Payload
    {
        return $this->payload;
    }

    public function manager(): JobManager
    {
        return $this->manager;
    }

    public function token(): Token
    {
        return $this->token;
    }

    public function audit(): AuditLog
    {
        return $this->audit;
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function presenter(): Presenter
    {
        return new Presenter($this->clock->now());
    }

    public function inspection(): Inspection
    {
        if ($this->inspection === null) {
            $builder = new InspectionBuilder(
                $this->environment,
                $this->gateway,
                $this->store,
                $this->history,
                $this->phpBinaries,
                $this->payload,
                $this->clock
            );

            $this->inspection = $builder->build();
            $this->findings = Checkup::withDefaultChecks()->run($this->inspection);
        }

        return $this->inspection;
    }

    public function findings(): array
    {
        $this->inspection();

        return $this->findings ?? [];
    }

    public function prepare(): void
    {
        $this->environment->ensureStateDirectories();
        $this->payload->deployIfNeeded();
    }
}
