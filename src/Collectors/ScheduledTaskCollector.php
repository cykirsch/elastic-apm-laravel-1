<?php

namespace AG\ElasticApmLaravel\Collectors;

use AG\ElasticApmLaravel\Contracts\DataCollector;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Nipwaayoni\Events\Transaction;
use Throwable;

/**
 * Collects info about scheduled tasks.
 */
class ScheduledTaskCollector extends EventDataCollector implements DataCollector
{
    public function getName(): string
    {
        return 'scheduled-task-collector';
    }

    public function registerEventListeners(): void
    {
        $this->app->events->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            $transaction_name = $this->getTransactionName($event);
            if (!$transaction_name) {
                return;
            }

            $transaction = $this->getTransaction($transaction_name);
            if ($transaction) {
                // Somehow, a transaction with the same name has already been created.
                // If so, ignore this job, otherwise the agent will throw an exception.
                return;
            }

            $transaction = $this->startTransaction($transaction_name);
            $this->addMetadata($transaction);
        });

        $this->app->events->listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            $transaction_name = $this->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $this->getTransaction($transaction_name);
                if ($transaction) {
                    $this->stopTransaction($transaction_name, 200);
                }
            }
        });

        $this->app->events->listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            $transaction_name = $this->getTransactionName($event);
            if ($transaction_name) {
                $transaction = $this->getTransaction($transaction_name);
                if ($transaction) {
                    $this->stopTransaction($transaction_name, 200);
                    $this->send($event);
                }
            }
        });
    }

    protected function startTransaction(string $transaction_name): Transaction
    {
        $start_time = microtime(true);

        return $this->agent->startTransaction(
            $transaction_name,
            [],
            $start_time
        );
    }

    protected function stopTransaction(string $transaction_name, int $result): void
    {
        // Stop the transaction and measure the time
        $this->agent->stopTransaction($transaction_name, ['result' => $result]);
        $this->agent->collectEvents($transaction_name);
    }

    protected function send($event): void
    {
        try {
            $this->agent->send();
        } catch (ClientException $exception) {
            Log::error($exception, ['api_response' => (string) $exception->getResponse()->getBody()]);
        } catch (Throwable $t) {
            Log::error($t->getMessage());
        }
    }

    /**
     * Return no name if we shouldn't record this transaction.
     *
     * @param ScheduledTaskStarting|ScheduledTaskSkipped|ScheduledTaskFinished $event
     */
    protected function getTransactionName($event): string
    {
        $transaction_name = $event->task->command;

        return $this->shouldIgnoreTransaction($transaction_name) ? '' : $transaction_name;
    }

    protected function addMetadata(Transaction $transaction): void
    {
        $transaction->setMeta([
            'type' => 'scheduled-task',
        ]);
        $transaction->setCustomContext([
            'ran_at' => Carbon::now()->toDateTimeString(),
            'memory' => [
                'peak' => round(memory_get_peak_usage(false) / 1024 / 1024, 2) . 'M',
                'peak_real' => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . 'M',
            ],
        ]);
    }
}