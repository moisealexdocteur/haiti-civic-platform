<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class Health extends BaseController
{
    public function index(): ResponseInterface
    {
        try {
            $db = db_connect();
            $db->query('SELECT 1');

            return $this->response->setJSON([
                'status'   => 'ok',
                'service'  => 'haiti-civic-platform',
                'database' => 'ok',
            ]);
        } catch (Throwable $exception) {
            return $this->response
                ->setStatusCode(503)
                ->setJSON([
                    'status'   => 'error',
                    'service'  => 'haiti-civic-platform',
                    'database' => 'unavailable',
                ]);
        }
    }
}
