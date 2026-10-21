<?php

declare(strict_types=1);

namespace App\Services\Backups\Process;

use InvalidArgumentException;
use SensitiveParameter;
use Symfony\Component\Process\Process;

/**
 * pg_dump / pg_restore for the control-plane database (docs/14 §2). argv
 * only; the password goes through PGPASSWORD, never argv (so it isn't
 * visible in `ps`). Connection values come from config, never user input,
 * and are still checked by grammar before they reach argv.
 */
final readonly class PostgresClient
{
    private const string NAME = '/^[A-Za-z0-9_.-]{1,253}$/D';

    public function __construct(
        private string $pgDump,
        private string $pgRestore,
        private string $host,
        private int $port,
        private string $username,
        #[SensitiveParameter]
        private string $password,
    ) {
        foreach (['host' => $host, 'username' => $username] as $field => $value) {
            if (preg_match(self::NAME, $value) !== 1) {
                throw new InvalidArgumentException("The database {$field} has an unexpected format.");
            }
        }
    }

    /**
     * The dashboard's own connection (config database.connections.<name>).
     */
    public static function fromConnection(string $connection): self
    {
        $config = config()->array("database.connections.{$connection}");

        return new self(
            config()->string('launchroom.backups.pg_dump_binary'),
            config()->string('launchroom.backups.pg_restore_binary'),
            is_string($config['host'] ?? null) ? $config['host'] : '127.0.0.1',
            is_numeric($config['port'] ?? null) ? (int) $config['port'] : 5432,
            is_string($config['username'] ?? null) ? $config['username'] : 'launchroom',
            is_string($config['password'] ?? null) ? $config['password'] : '',
        );
    }

    public function dump(string $database): Process
    {
        return new Process([
            $this->pgDump, '--format=custom', '--no-owner', '--no-privileges',
            '--host='.$this->host, '--port='.$this->port, '--username='.$this->username,
            '--dbname='.self::database($database),
        ], null, $this->env());
    }

    /**
     * Restores a custom-format dump read from stdin into $database
     * (dropping objects that exist; single transaction, stop on error).
     */
    public function restore(string $database): Process
    {
        return new Process([
            $this->pgRestore, '--clean', '--if-exists', '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error',
            '--host='.$this->host, '--port='.$this->port, '--username='.$this->username,
            '--dbname='.self::database($database),
        ], null, $this->env());
    }

    /**
     * Runs `pg_dump` of $database and hands its output to $sink in pieces of
     * $chunkBytes (the last one shorter; at least one call). Used for the
     * control-plane hand-over (docs/14 §13.4): nothing is staged unencrypted.
     *
     * @param  callable(string): void  $sink
     * @return int bytes dumped
     *
     * @throws ProcessFailed
     */
    public function dumpInChunks(string $database, callable $sink, int $chunkBytes, int $timeout): int
    {
        $dump = $this->dump($database);
        $dump->setTimeout($timeout);
        $dump->start();

        $buffer = '';
        $bytes = 0;
        $calls = 0;

        foreach ($dump->getIterator(Process::ITER_SKIP_ERR) as $data) {
            $buffer .= $data;
            $bytes += strlen($data);

            while (strlen($buffer) >= $chunkBytes) {
                $sink(substr($buffer, 0, $chunkBytes));
                $buffer = substr($buffer, $chunkBytes);
                $calls++;
            }
        }

        $dump->wait();

        if (! $dump->isSuccessful()) {
            throw ProcessFailed::producer('dump the database', ProcessOutcome::from($dump, $this->secrets()));
        }

        if ($buffer !== '' || $calls === 0) {
            $sink($buffer);
        }

        return $bytes;
    }

    /**
     * Restores a custom-format dump given as plaintext pieces into $database
     * (`pg_restore --clean --if-exists --single-transaction`), pulling the
     * next piece only when pg_restore's stdin takes more.
     *
     * @param  iterable<string>  $chunks
     * @return int bytes restored
     *
     * @throws ProcessFailed
     */
    public function restoreFrom(string $database, iterable $chunks, int $timeout): int
    {
        $restore = $this->restore($database);
        $bytes = 0;
        $restore->setInput((static function () use ($chunks, &$bytes): \Generator {
            foreach ($chunks as $chunk) {
                $bytes += strlen($chunk);

                yield $chunk;
            }
        })());
        $restore->setTimeout($timeout);
        $restore->run();

        if (! $restore->isSuccessful()) {
            throw ProcessFailed::producer('restore the database', ProcessOutcome::from($restore, $this->secrets()));
        }

        return $bytes;
    }

    /**
     * @return list<string>
     */
    public function secrets(): array
    {
        return strlen($this->password) >= 4 ? [$this->password] : [];
    }

    public static function database(string $database): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,63}$/D', $database) !== 1) {
            throw new InvalidArgumentException('Database names are 1–63 letters, digits and underscores.');
        }

        return $database;
    }

    /**
     * @return array<string, string|false>
     */
    private function env(): array
    {
        return [
            'PGPASSWORD' => $this->password,
            // Never read a service file or passfile from the container.
            'PGSERVICEFILE' => false,
            'PGPASSFILE' => '/dev/null',
            'PGOPTIONS' => false,
        ];
    }
}
