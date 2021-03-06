<?php

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Contracts\DataCollector;
use AG\ElasticApmLaravel\Services\ApmCollectorService;
use Codeception\Test\Unit;
use Illuminate\Config\Repository as Config;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;

class ApmCollectorServiceTest extends Unit
{
    private const SPAN_NAME = 'test-measure';

    private $appMock;
    private $configMock;
    private $eventsMock;

    private $collectorService;

    protected function setUp(): void
    {
        parent::setup();

        $this->appMock = Mockery::mock(Application::class);
        $this->configMock = Mockery::mock(Config::class);
        $this->eventsMock = Mockery::mock(Dispatcher::class);

        $this->configMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.active')
            ->andReturn(true);
        $this->configMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.cli.active')
            ->andReturn(true);

        $this->collectorService = new ApmCollectorService(
            $this->appMock,
            $this->eventsMock,
            $this->configMock
        );
    }

    public function testStartMeasureEventWithDefaultValues()
    {
        $this->eventsMock->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function ($event) {
                $this->assertEquals(self::SPAN_NAME, $event->name);
                $this->assertEquals('request', $event->type);
                $this->assertNull($event->action);
                $this->assertNull($event->label);
                $this->assertNull($event->start_time);

                return true;
            }));

        $this->collectorService->startMeasure(self::SPAN_NAME);
    }

    public function testStartMeasureEventWithValues()
    {
        $this->eventsMock->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function ($event) {
                $this->assertEquals(self::SPAN_NAME, $event->name);
                $this->assertEquals('db.query', $event->type);
                $this->assertEquals('SELECT', $event->action);
                $this->assertEquals('Company SELECT', $event->label);
                $this->assertEquals(1000.0, $event->start_time);

                return true;
            }));

        $this->collectorService->startMeasure(
            self::SPAN_NAME,
            'db.query',
            'SELECT',
            'Company SELECT',
            1000.0,
        );
    }

    public function testStopMeasureEventWithDefaultValues()
    {
        $this->eventsMock->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function ($event) {
                $this->assertEquals(self::SPAN_NAME, $event->name);
                $this->assertEquals([], $event->params);

                return true;
            }));

        $this->collectorService->stopMeasure(self::SPAN_NAME);
    }

    public function testStopMeasureEventWithValues()
    {
        $this->eventsMock->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function ($event) {
                $this->assertEquals(self::SPAN_NAME, $event->name);
                $this->assertEquals(['user' => 1], $event->params);

                return true;
            }));

        $this->collectorService->stopMeasure(
            self::SPAN_NAME,
            ['user' => 1],
        );
    }

    public function testAddCollector()
    {
        $agentMock = Mockery::mock(Agent::class);
        $collectorMock = Mockery::mock(DataCollector::class);

        $agentMock->shouldReceive('addCollector')
            ->once()
            ->with($collectorMock);

        $this->appMock->shouldReceive('make')
            ->once()
            ->with(Agent::class)
            ->andReturn($agentMock);

        $this->appMock->shouldReceive('make')
            ->once()
            ->with('App\Collectors\MyCollector')
            ->andReturn($collectorMock);

        $this->collectorService->addCollector('App\Collectors\MyCollector');
    }

    public function testAddCollectorWithDisabledAgent()
    {
        $this->configMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.active')
            ->andReturn(false);

        $this->collectorService = new ApmCollectorService(
            $this->appMock,
            $this->eventsMock,
            $this->configMock
        );

        $this->appMock->shouldNotReceive('make');

        $this->collectorService->addCollector('App\Collectors\MyCollector');
    }

    public function testAddCollectorWithDisabledAgentForCli()
    {
        $this->configMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.active')
            ->andReturn(true);
        $this->configMock->shouldReceive('get')
            ->once()
            ->with('elastic-apm-laravel.cli.active')
            ->andReturn(false);

        $this->collectorService = new ApmCollectorService(
            $this->appMock,
            $this->eventsMock,
            $this->configMock
        );

        $this->appMock->shouldNotReceive('make');

        $this->collectorService->addCollector('App\Collectors\MyCollector');
    }
}
