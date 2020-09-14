<?php

namespace AG\ElasticApmLaravel\Services;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Events\StartMeasuring;
use AG\ElasticApmLaravel\Events\StopMeasuring;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use PhilKra\Events\Transaction;
use Throwable;

class ApmCollectorService
{
    /**
     * @var Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * @var Illuminate\Events\Dispatcher
     */
    protected $events;

    /**
     * @var bool
     */
    private $is_agent_disabled;

    public function __construct(Application $app, Dispatcher $events, ApmConfigService $config)
    {
        $this->app = $app;
        $this->events = $events;

        $this->is_agent_disabled = $config->isAgentDisabled();
    }

    public function startMeasure(
        string $name,
        string $type = 'request',
        ?string $action = null,
        ?string $label = null,
        ?float $start_time = null
    ) {
        $this->events->dispatch(
            new StartMeasuring(
                $name,
                $type,
                $action,
                $label,
                $start_time
            )
        );
    }

    public function stopMeasure(
        string $name,
        array $params = []
    ) {
        $this->events->dispatch(
            new StopMeasuring(
                $name,
                $params
            )
        );
    }

    public function addCollector(string $collector_class): void
    {
        if ($this->is_agent_disabled) {
            return;
        }

        $this->app->make(Agent::class)->addCollector(
            $this->app->make($collector_class)
        );
    }

    public function captureThrowable(Throwable $thrown, array $context = [], ?Transaction $parent = null)
    {
        if ($this->is_agent_disabled) {
            return;
        }

        $this->app->make(Agent::class)->captureThrowable($thrown, $context, $parent);
    }
}
