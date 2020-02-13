<?php

namespace AG\ElasticApmLaravel;

use AG\ElasticApmLaravel\Collectors\DBQueryCollector;
use AG\ElasticApmLaravel\Collectors\FrameworkCollector;
use AG\ElasticApmLaravel\Collectors\HttpRequestCollector;
use AG\ElasticApmLaravel\Collectors\Interfaces\DataCollectorInterface;
use AG\ElasticApmLaravel\Collectors\JobCollector;
use AG\ElasticApmLaravel\Collectors\SpanCollector;
use AG\ElasticApmLaravel\Events\LazySpan;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PhilKra\Agent as PhilKraAgent;
use PhilKra\Events\Transaction;

/**
 * The Elastic APM agent sends performance metrics and error logs to the APM Server.
 *
 * The agent records events, like HTTP requests and database queries.
 * The Agent automatically keeps track of queries to your data stores
 * to measure their duration and metadata (like the DB statement), as well as HTTP related information.
 *
 * These events, called Transactions and Spans, are sent to the APM Server.
 * The APM Server converts them to a format suitable for Elasticsearch,
 * and sends them to an Elasticsearch cluster. You can then use the APM app
 * in Kibana to gain insight into latency issues and error culprits within your application.
 */
class Agent extends PhilKraAgent
{
    protected $tranStack;
    protected $collectors;
    protected $request_start_time;

    public function __construct(array $config, float $request_start_time)
    {
        parent::__construct($config);

        $this->request_start_time = $request_start_time;
        $this->collectors = new Collection();
        $this->tranStack = new Collection();
    }

    public function registerCollectors(Application $app): void
    {
        if (false !== config('elastic-apm-laravel.spans.querylog.enabled')) {
            // DB Queries collector
            $this->collectors->put(
                DBQueryCollector::getName(),
                new DBQueryCollector($app, $this->request_start_time)
            );
        }

        // Laravel init collector
        $this->collectors->put(
            FrameworkCollector::getName(),
            new FrameworkCollector($app, $this->request_start_time)
        );

        // Http request collector
        $this->collectors->put(
            HttpRequestCollector::getName(),
            new HttpRequestCollector($app, $this->request_start_time)
        );

        // Job collector
        $this->collectors->put(
            JobCollector::getName(),
            new JobCollector($app, $this, $this->request_start_time)
        );

        // Collector for manual measurements throughout the app
        $this->collectors->put(
            SpanCollector::getName(),
            new SpanCollector($app, $this->request_start_time)
        );
    }

    public function getCollector(string $name): DataCollectorInterface
    {
        return $this->collectors->get($name);
    }

    public function collectEvents(string $transaction_name, string $collect_by = null): void
    {
        $max_trace_items = config('elastic-apm-laravel.spans.maxTraceItems');
        Log::info('Collecting for '.$transaction_name.', '.($collect_by === null ? $transaction_name : 'empty'));

        $transaction = $this->getTransaction($transaction_name);
        $this->collectors->each(function ($collector) use ($transaction, $max_trace_items, $collect_by) {
            Log::info('Collecting from '.$collector->getName());

            $collector->collect($collect_by ?? $transaction->getTransactionName())
                ->take($max_trace_items)
                ->each(function ($measure) use ($transaction) {
                    $event = new LazySpan($measure['label'], $transaction);
                    $event->setType($measure['type']);
                    $event->setAction($measure['action']);
                    $event->setContext($measure['context']);
                    $event->setStartTime($measure['start']);
                    $event->setDuration($measure['duration']);

                    Log::info('Collected '.$measure['label'].' at '.$measure['start'].' and took '.$measure['duration']);

                    $this->putEvent($event);
                });
        });
    }

    /**
     * Add on to the parent method to handle parent transactions.
     */
    public function startTransaction(string $name, array $context = [], float $start = null): Transaction
    {

        $parent = $this->getLatestTransactionName();
        if ($parent) {
            Log::info('Has parent, starting with current time: '.$name);
            $transaction = parent::startTransaction($name, $context);
            $transaction->setParent($this->getTransaction($parent));
        } else {
            Log::info('No parent, starting with request time: '.$name);
            $transaction = parent::startTransaction($name, $context, $start);
        }

        $this->tranStack->push($transaction->getTransactionName());
        Log::info('Started '.$name);

        return $transaction;
    }

    /**
     * Add on to the parent method to handle parent transactions.
     */
    public function stopTransaction(string $name, array $meta = [])
    {
        Log::info('Stopping '.$name);
        // TODO? This *should* always be right but perhaps it would be better to remove by name just in case
        $this->tranStack->pop();

        parent::stopTransaction($name, $meta);

        $this->collectEvents($name);

        // If there are no more open transactions, also collect the ones without transactions specified
        if ($this->tranStack->isEmpty()) {
            Log::info('Collecting empties');
            $this->collectEvents($name, '');
        } else {
            Log::info('Still open: '.$this->tranStack->count());
        }
    }

    public function getLatestTransactionName()
    {
        return $this->tranStack->last() ?? '';
    }
}
