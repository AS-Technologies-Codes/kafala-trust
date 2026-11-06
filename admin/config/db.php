<?php

define(
    'KIFALA_API_TOKEN',
    'dfsdgs435435kifala.orgdsfdsfa22342222@@222'
);

define('CURRENCY', 'Rs');

define('WEB_URL', 'https://Kifalatrust.org/admin/');
define('ROOT_PATH', 'https://Kifalatrust.org/admin/');

$company_contact= "021-xxx-xxx-xxx";
$company_email= "info@astechnologies.pk";
$company_website= "https://astechnologies.pk";


$servername = "localhost";
$username = "u999788634_kifala_trust";
$password = "Trust@@@786@@##4";
$dbname = "u999788634_kifala_trust";
   // Create connection
$conn = new mysqli($servername, $username, $password, $dbname);
   // Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$con =$conn;

function getPdoConnection() {
    $dbType = 'mysql'; //Can be 'mysql', 'sqlsrv', or 'oracle'
    $host = "localhost";
    $username = "u999788634_kifala_trust";
    $password = "Trust@@@786@@##4";
    $dbname = "u999788634_kifala_trust";

    try {
        switch ($dbType) {
            case 'mysql':
                // MySQL DSN (Data Source Name)
                $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
                break;

            case 'sqlsrv':
                // SQL Server DSN
                $dsn = "sqlsrv:Server=$host;Database=$dbname";
                break;

            case 'oracle':
                // Oracle DSN
                $dsn = "oci:dbname=//$host/$dbname;charset=AL32UTF8";  
                break;

            default:
                throw new Exception("Unsupported database type: $dbType");
        }

        // Create a PDO instance
        $pdo = new PDO($dsn, $username, $password);
        // Set PDO error mode to exception
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        date_default_timezone_set('UTC');
        $pdo->exec("SET time_zone = '+00:00'");

        return $pdo;

    } catch (PDOException $e) {
        die("Connection failed: " . $e->getMessage());
    }
}


// Closing the connection (optional in PDO but explicitly done here)
function closePdoConnection(&$pdo) {
    $pdo = null;
}

?>