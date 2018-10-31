<?php

require_once('vendor/autoload.php');
require_once('q-config.php');


// -------------------------------------------------------------------------------------------------
function clean($array) {
    return array_filter(array_map('trim', $array), function($item) {
        return $item != '';
    });
}

// -------------------------------------------------------------------------------------------------
function match($a, $b) {
    return count(array_diff($a, $b)) == 0 && count(array_diff($b, $a)) == 0;
}

// -------------------------------------------------------------------------------------------------
function array_pluck($array, $field) {
    return array_map(function($row) use ($field) {
        return $row[$field];
    }, $array);
}
// -------------------------------------------------------------------------------------------------
class QArto {
    function __construct() {
        $container = new \Slim\Container;
        $this->db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                            DB_USER,
                            DB_PASSWORD);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $this->app = new \Slim\App($container);
    }
    // ---------------------------------------------------------------------------------------------
    function install() {
        $queries = clean(explode(";", file_get_contents("create.sql")));
        foreach ($queries as $query) {
            echo $query . "\n";
            echo $this->query($query) . "\n";
        }
        $accounts = array_pluck($this->query("SELECT name FROM account_types"), "name");
        $diff = array_diff(array("admin", "moderator", "user", "beginer"), $accounts);
        if (count($diff) > 0) {
            $query = "INSERT INTO account_types(name) VALUES('" . implode("'), ('", $diff) . "')";
            echo $this->query($query) . "\n";
        }
    }
    // ---------------------------------------------------------------------------------------------
    function register($username, $email, $password, $type) {
        $ret = $this->query("SELECT count(*) FROM users WHERE username = ? OR email = ?", $username, $email);
        if ($ret[0]['count(*)'] > 0) {
            throw new Error("user already exists");
        }
        $password = password_hash($password, PASSWORD_BCRYPT);
        $this->query("INSERT INTO users(email, username, password, type) SELECT ?, ?, ?, id " .
                     "FROM account_types WHERE account_types.name = ?",
                     $email,
                     $username,
                     $password,
                     $type);
        return $this->lastInsertId();
    }
    // ---------------------------------------------------------------------------------------------
    function lastInsertId() {
        return $this->db->lastInsertId();
    }
    // ---------------------------------------------------------------------------------------------
    function get_var($array) {
        return array_values($array[0])[0];
    }
    // ---------------------------------------------------------------------------------------------
    function query($query) {
        $args = func_get_args();
        array_shift($args);
        if (count($args) == 0) {
            $res = $this->db->query($query);
        } else {
            $res = $this->db->prepare($query);
            if ($res) {
                if (!$res->execute($args)) {
                    throw Exception("execute query failed");
                }
            } else {
                throw Exception("wrong query");
            }
        }
        if ($res) {
            if (preg_match("/^\s*INSERT|UPDATE|DELETE|ALTER|CREATE|DROP/i", $query)) {
                return $res->rowCount();
            } else {
                    return $res->fetchAll(PDO::FETCH_ASSOC);
            }
        } else {
            throw new Exception("Query Error");
        }
    }
    // ---------------------------------------------------------------------------------------------
    function login($user, $password) {
        $data = $this->query("SELECT password, name as role FROM users LEFT JOIN account_types " .
                             "as a ON a.id = type WHERE username = ?", $user);
        if (count($data) == 1) {
            $data = $data[0];
            $role = $data['role'];
            $hash = $data['password'];
            if (password_verify($password, $hash)) {
                $_SESSION['user'] = $user;
                $_SESSION['role'] = $role;
            }
        }
    }
    // ---------------------------------------------------------------------------------------------
    function __call($name, $args) {
        return call_user_func_array(array($this->app, $name), $args);
    }
}


session_name("QAID");
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

if (isset($_SESSION['user'])) {
    echo $_SESSION['user'] . " " . $_SESSION['role'] . "\n";
}


$app = new QArto();
header('Content-Type: text/plain');
echo ($app->install() ? 'true' : 'false') . "\n";

//$app->register("admin", "admin@jcubic.pl", "some_password", "admin");

$app->login("admin", "some_password");

?>
