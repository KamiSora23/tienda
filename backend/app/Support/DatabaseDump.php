<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;

/**
 * Respaldo y restauración de la base MySQL con mysqldump / mysql.
 * Funciona igual en Windows (Laragon) y en Docker/Linux; los datos de conexión salen de la
 * configuración (no de env(), que queda vacío con la configuración en caché).
 */
final class DatabaseDump
{
    /** Ruta del programa: DB_DUMP_BINARY / DB_CLIENT_BINARY, el de Laragon en Windows o el del PATH. */
    public static function binary(string $tool): string
    {
        $configured = config($tool === 'mysqldump' ? 'database.dump_binary' : 'database.client_binary');
        if ($configured) return $configured;
        if (PHP_OS_FAMILY === 'Windows') {
            $found = glob('C:\\laragon\\bin\\mysql\\mysql-*\\bin\\'.$tool.'.exe') ?: [];
            rsort($found, SORT_NATURAL);
            if ($found) return $found[0];
        }

        return $tool;
    }

    private static function connection(): array
    {
        $c = config('database.connections.'.config('database.default'));

        $extra = array_values(array_filter(explode(' ', (string) config('database.dump_options'))));

        return [['--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'], ...$extra], $c['database'], (string) ($c['password'] ?? '')];
    }

    /** Crea el archivo de respaldo. Devuelve null si salió bien o el mensaje de error. */
    public static function dump(string $file): ?string
    {
        [$args, $database, $password] = self::connection();
        $result = Process::env(['MYSQL_PWD' => $password])->timeout(600)
            ->run([self::binary('mysqldump'), ...$args, '--single-transaction', '--routines', '--no-tablespaces', '--result-file='.$file, $database]);

        return $result->successful() ? null : (trim($result->errorOutput()) ?: 'Código de salida '.$result->exitCode());
    }

    /** Restaura un respaldo sobre la base actual. Devuelve null si salió bien o el mensaje de error. */
    public static function restore(string $file): ?string
    {
        [$args, $database, $password] = self::connection();
        $result = Process::env(['MYSQL_PWD' => $password])->timeout(900)
            ->run([self::binary('mysql'), ...$args, $database, '--execute=source '.str_replace('\\', '/', $file)]);

        return $result->successful() ? null : (trim($result->errorOutput()) ?: 'Código de salida '.$result->exitCode());
    }
}
