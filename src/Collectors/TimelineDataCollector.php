<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Agent;
use AG\ElasticApmLaravel\Collectors\Interfaces\DataCollectorInterface;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Collects info about the request duration as well as providing
 * a way to log duration of any operations.
 */
class TimelineDataCollector implements DataCollectorInterface
{
    protected $started_measures;
    protected $measures;
    protected $request_start_time;
    protected $agent;

    public function __construct(float $request_start_time)
    {
        $this->started_measures = new Collection();
        $this->measures = new Collection();
        $this->request_start_time = $request_start_time;
        $this->agent = app(Agent::class);
    }

    /**
     * Starts a measure.
     */
    public function startMeasure(
        string $name,
        string $type = 'request',
        string $action = null,
        string $label = null,
        float $start_time = null
    ): void {
        $start = $start_time ?? microtime(true);

        $this->started_measures->put($name, [
            'label' => $label ?: $name,
            'start' => $start - $this->getTransactionStartTime($this->agent->getLatestTransactionName()),
            'type' => $type,
            'action' => $action,
            'transaction' => $this->agent->getLatestTransactionName(),
        ]);
        Log::info('measuring '.$label.' --- '.$this->agent->getLatestTransactionName());

    }

    public function getTransactionStartTime($name)
    {
        if ($name) {
            $y = $this->agent->getTransaction($name);
            dump($name, $y->start_time, $this->request_start_time);
            return $y->start_time; //hmm, how can I make the beginning of the job a later time?
        }

        return $this->request_start_time;
    }

    /**
     * Check if a measure exists.
     */
    public function hasStartedMeasure(string $name): bool
    {
        return $this->started_measures->has($name);
    }

    /**
     * Stops a measure.
     */
    public function stopMeasure(string $name, array $params = []): void
    {
        $end = microtime(true);
        if (!$this->hasStartedMeasure($name)) {
            throw new Exception("Failed stopping measure '{$name}' because it hasn't been started.");
        }

        $measure = $this->started_measures->pull($name);
        $this->addMeasure(
            $measure['label'],
            $measure['start'],
            $end - $this->getTransactionStartTime($this->agent->getLatestTransactionName()),
            $measure['type'],
            $measure['action'],
            $params,
            $measure['transaction']
        );
    }

    /**
     * Adds a measure.
     */
    public function addMeasure(
        string $label,
        float $start,
        float $end,
        string $type = 'request',
        string $action = 'request',
        array $context = [],
        string $transaction = ''
    ): void {
        $this->measures->push([
            'label' => $label,
            'start' => $this->toMilliseconds($start),
            'duration' => $this->toMilliseconds($end - $start),
            'type' => $type,
            'action' => $action,
            'context' => $context,
            'transaction' => $transaction,
        ]);

        Log::info('measured '.$label.' --- '.$transaction);
    }

    /**
     * Returns an array of all measures.
     */
    public function getMeasures(): Collection
    {
        return $this->measures;
    }

    public function collect(string $transaction_name): Collection
    {
        // dump($this->started_measures);
        $this->started_measures
            ->where('transaction', '=', $transaction_name)
            ->keys()
            ->each(function ($name) {
                $this->stopMeasure($name);
            });

        $this->measures
            ->where('transaction', '=', $transaction_name)
            ->each(function ($measure) {
                Log::info('collecting '.$measure['label']);
            });


        return $this->measures->where('transaction', '=', $transaction_name);
    }

    public static function getName(): string
    {
        return 'timeline';
    }

    private function toMilliseconds(float $time): float
    {
        return round($time * 1000, 3);
    }
}
