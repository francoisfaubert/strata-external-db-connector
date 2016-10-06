# External DB Connector for Strata

A class your objects can inherit from to allow your Strata models to
connect to other MySQL databases than the one hosting the Wordpress installation

## Installation

Require the dependency through Composer :

~~~
$ composer require francoisfaubert/strata-external-db-connector
~~~

## Implementation

This abstract class is best implemented by creating a service object which will inherit `DbConnector`.

You models will then feed an instance of the service class when a `query()` invocation is made.

## Example

In this example we will create a Strata Model named 'Rates' that connects to an external MySQL database using a custom service.

First start by creating a file under `~/src/Model/Service/` named `RatesService.php`.

The file should contain connection information as well optional pre-formatted queries. It also has the mandate of returning `RateEntity` elements upon each queries' `fetch()`.

~~~ php
<?php
namespace App\Model\Service;

use Francoisfaubert\Service\Db\Connector\MysqlConnector;
use App\Model\Rate;

class RatesService extends MysqlConnector {

    public function init()
    {
        $this->servername = getenv('RATES_DB_HOST');
        $this->dbname = getenv('RATES_DB_NAME');
        $this->username = getenv('RATES_DB_USER');
        $this->password = getenv('RATES_DB_PASSWORD');
    }

    public function fetch()
    {
        $formatted = array();

        foreach ((array)parent::fetch() as $treatment) {
            $formatted[] = Rate::getEntity((object)$treatment);
        }

        return $formatted;
    }

    public function getByLocationCode($locationCode)
    {
        return $this
            ->select("rates.*")
            ->from("rates", "r")
            ->where("r.location = ?", (int)$locationCode)
            ->orderBy("r.name", "ASC")
            ->fetch();
    }

    public function getWithCategories()
    {
        return $this
            ->select("rates.*")
            ->from("rates", "r")
            ->join("locations", "locations.ID = r.location_ID")
            ->fetch();
    }
}
~~~


Secondly, create a new `Rate` Model using Strata's `generate` command.

~~~ bash
$ ./strata generate model rate
~~~


Finally configure the class to use the new service as query adapter.

~~~ php
<?php
namespace App\Model;

use App\Model\Service\RatesService;

class Rate extends AppCustomPostType
{
    public static function repo()
    {
        $rates = new RatesService();
        $rates->init();

        return $rates;
    }

}
~~~
