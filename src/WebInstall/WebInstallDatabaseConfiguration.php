<?php

declare(strict_types=1);

namespace Larena\Core\WebInstall;

final readonly class WebInstallDatabaseConfiguration
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
    ) {
        if (preg_match('/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/D', $host) !== 1
            || $port < 1 || $port > 65535
            || preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $database) !== 1
            || $username === '' || strlen($username) > 128
            || strlen($password) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $username.$password) === 1) {
            throw new WebInstallException('web_install_database_input_invalid');
        }
    }

    /** @return array{connection:string,host:string,port:int,database:string,username:string,password:string} */
    public function toPrivateArray(): array
    {
        return [
            'connection' => 'mysql',
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
