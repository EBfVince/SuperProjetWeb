<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\DBAL\Driver\Mysqli;

use Doctrine\DBAL\Driver\Connection as Connection;
use Doctrine\DBAL\Driver\PingableConnection;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\ParameterType;
use function defined;
use function floor;
use function func_get_args;
use function in_array;
use function ini_get;
use function mysqli_errno;
use function mysqli_error;
use function mysqli_init;
use function mysqli_options;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function stripos;

/**
 * @author Kim Hemsø Rasmussen <kimhemsoe@gmail.com>
 * @author Till Klampaeckel <till@php.net>
 */
class MysqliConnection implements Connection, PingableConnection, ServerInfoAwareConnection
{
    /**
     * Name of the option to set connection flags
     */
    const OPTION_FLAGS = 'flags';

    /**
     * @var \mysqli
     */
    private $_conn;

    /**
     * @param array  $params
     * @param string $username
     * @param string $password
     * @param array  $driverOptions
     *
     * @throws \Doctrine\DBAL\Driver\Mysqli\MysqliException
     */
    public function __construct(array $params, $username, $password, array $driverOptions = [])
    {
        $port = $params['port'] ?? ini_get('mysqli.default_port');

        // Fallback to default MySQL port if not given.
        if ( ! $port) {
            $port = 3306;
        }

        $socket = $params['unix_socket'] ?? ini_get('mysqli.default_socket');
        $dbname = $params['dbname'] ?? null;

        $flags = $driverOptions[static::OPTION_FLAGS] ?? null;

        $this->_conn = mysqli_init();

        $this->setSecureConnection($params);
        $this->setDriverOptions($driverOptions);

        set_error_handler(function () {});
        try {
            if ( ! $this->_conn->real_connect($params['host'], $username, $password, $dbname, $port, $socket, $flags)) {
                throw new MysqliException($this->_conn->connect_error, $this->_conn->sqlstate ?? 'HY000', $this->_conn->connect_errno);
            }
        } finally {
            restore_error_handler();
        }

        if (isset($params['charset'])) {
            $this->_conn->set_charset($params['charset']);
        }
    }

    /**
     * Retrieves mysqli native resource handle.
     *
     * Could be used if part of your application is not using DBAL.
     *
     * @return \mysqli
     */
    public function getWrappedResourceHandle()
    {
        return $this->_conn;
    }

    /**
     * {@inheritdoc}
     *
     * The server version detection includes a special case for MariaDB
     * to support '5.5.5-' prefixed versions introduced in Maria 10+
     * @link https://jira.mariadb.org/browse/MDEV-4088
     */
    public function getServerVersion()
    {
        $serverInfos = $this->_conn->get_server_info();
        if (false !== stripos($serverInfos, 'mariadb')) {
            return $serverInfos;
        }

        $majorVersion = floor($this->_conn->server_version / 10000);
        $minorVersion = floor(($this->_conn->server_version - $majorVersion * 10000) / 100);
        $patchVersion = floor($this->_conn->server_version - $majorVersion * 10000 - $minorVersion * 100);

        return $majorVersion . '.' . $minorVersion . '.' . $patchVersion;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresQueryForServerVersion()
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($prepareString)
    {
        return new MysqliStatement($this->_conn, $prepareString);
    }

    /**
     * {@inheritdoc}
     */
    public function query()
    {
        $args = func_get_args();
        $sql = $args[0];
        $stmt = $this->prepare($sql);
        $stmt->execute();

        return $stmt;
    }

    /**
     * {@inheritdoc}
     */
    public function quote($input, $type = ParameterType::STRING)
    {
        return "'". $this->_conn->escape_string($input) ."'";
    }

    /**
     * {@inheritdoc}
     */
    public function exec($statement)
    {
        if (false === $this->_conn->query($statement)) {
            throw new MysqliException($this->_conn->error, $this->_conn->sqlstate, $this->_conn->errno);
        }

        return $this->_conn->affected_rows;
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId($name = null)
    {
        return $this->_conn->insert_id;
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction()
    {
        $this->_conn->query('START TRANSACTION');

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        return $this->_conn->commit();
    }

    /**
     * {@inheritdoc}non-PHPdoc)
     */
    public function rollBack()
    {
        return $this->_conn->rollback();
    }

    /**
     * {@inheritdoc}
     */
    public function errorCode()
    {
        return $this->_conn->errno;
    }

    /**
     * {@inheritdoc}
     */
    public function errorInfo()
    {
        return $this->_conn->error;
    }

    /**
     * Apply the driver options to the connection.
     *
     * @param array $driverOptions
     *
     * @throws MysqliException When one of of the options is not supported.
     * @throws MysqliException When applying doesn't work - e.g. due to incorrect value.
     */
    private function setDriverOptions(array $driverOptions = [])
    {
        $supportedDriverOptions = [
            \MYSQLI_OPT_CONNECT_TIMEOUT,
            \MYSQLI_OPT_LOCAL_INFILE,
            \MYSQLI_INIT_COMMAND,
            \MYSQLI_READ_DEFAULT_FILE,
            \MYSQLI_READ_DEFAULT_GROUP,
        ];

        if (defined('MYSQLI_SERVER_PUBLIC_KEY')) {
            $supportedDriverOptions[] = \MYSQLI_SERVER_PUBLIC_KEY;
        }

        $exceptionMsg = "%s option '%s' with value '%s'";

        foreach ($driverOptions as $option => $value) {

            if ($option === static::OPTION_FLAGS) {
                continue;
            }

            if (!in_array($option, $supportedDriverOptions, true)) {
                throw new MysqliException(
                    sprintf($exceptionMsg, 'Unsupported', $option, $value)
                );
            }

            if (@mysqli_options($this->_conn, $option, $value)) {
                continue;
            }

            $msg  = sprintf($exceptionMsg, 'Failed to set', $option, $value);
            $msg .= sprintf(', error: %s (%d)', mysqli_error($this->_conn), mysqli_errno($this->_conn));

            throw new MysqliException(
                $msg,
                $this->_conn->sqlstate,
                $this->_conn->errno
            );
        }
    }

    /**
     * Pings the server and re-connects when `mysqli.reconnect = 1`
     *
     * @return bool
     */
    public function ping()
    {
        return $this->_conn->ping();
    }

    /**
     * Establish a secure connection
     *
     * @param array $params
     * @throws MysqliException
     */
    private function setSecureConnection(array $params)
    {
        if (! isset($params['ssl_key']) &&
            ! isset($params['ssl_cert']) &&
            ! isset($params['ssl_ca']) &&
            ! isset($params['ssl_capath']) &&
            ! isset($params['ssl_cipher'])
        ) {
            return;
        }

        $this->_conn->ssl_set(
            $params['ssl_key']    ?? null,
            $params['ssl_cert']   ?? null,
            $params['ssl_ca']     ?? null,
            $params['ssl_capath'] ?? null,
            $params['ssl_cipher'] ?? null
        );
    }
}
