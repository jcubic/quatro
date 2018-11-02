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
    static $query_with_votes = "SELECT q.id, title, (SELECT count(*) FROM post_votes AS p LEFT JOIN votes ON p.id = connection WHERE up = true AND p.id = q.votes) as up_votes, (SELECT count(*) FROM post_votes AS p LEFT JOIN votes ON p.id = connection WHERE up = false AND p.id = q.votes) as down_votes";
    function __construct() {
        header_remove("X-Powered-By");
        header("X-Frame-Options: Deny");
        header('X-Content-Type-Options: nosniff');
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
        $diff = array_diff(array("admin", "moderator", "user", "beginer", "anonymous"), $accounts);
        if (count($diff) > 0) {
            $query = "INSERT INTO account_types(name) VALUES('" . implode("'), ('", $diff) . "')";
            echo $this->query($query) . "\n";
        }
    }
    // ---------------------------------------------------------------------------------------------
    function register($username, $email, $password, $type) {
        $ret = $this->query("SELECT count(*) FROM users LEFT JOIN account_types AS a ON a.id = ".
                            "type WHERE username = ? OR (email = ? AND a.name <> 'anonymous')",
                            $username,
                            $email);
        if ($ret[0]['count(*)'] > 0) {
            throw new Exception("user already exists");
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
    function create_tag($name, $description = NULL) {
        $name = trim($name);
        if ($description != NULL) {
            $description = trim($description);
        }
        if ($name != "") {
            $ret = $this->query("SELECT * FROM tags WHERE name = ?", $name);
            if (count($ret) == 0) {
                $this->query("INSERT INTO tags(name, description) VALUES(?, ?)", $name, $description);
            }
        }
    }
    // ---------------------------------------------------------------------------------------------
    function create_anon($username, $email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email address");
        }
        $this->query("INSERT INTO users values(username, email, type) SELECT ?, ?, id FROM ".
                     "account_types AS a WHERE a.name = 'anonymous'", $username, $email);
        return $this->lastInsertId();
    }
    // ---------------------------------------------------------------------------------------------
    function get_user_id($username) {
        $ret = $this->query("SELECT users.id FROM users LEFT JOIN account_types AS a ON ".
                            "a.id = type WHERE a.name <> 'anonymous' AND username = ?", $username);
        if (count($ret) == 1) {
            return $ret[0]['id'];
        }
    }
    // ---------------------------------------------------------------------------------------------
    function vote($userid, $post_id, $vote, $table = 'questions') {
        $this->query("INSERT INTO votes(voter, up, connection) SELECT ?, ?, votes FROM $table WHERE " .
                     "id = ?",
                     $userid,
                     $vote,
                     $post_id);
    }
    // ---------------------------------------------------------------------------------------------
    function ask_question($userid, $title, $text, $tags) {
        $tags = implode(",", array_map(function($tag) {
            return $this->db->quote($tag);
        }, clean($tags)));
        $title = trim($title);
        $text = trim($text);
        $tags = array_pluck($this->query("SELECT id,name FROM tags WHERE name in ($tags)"), "id");
        $this->query("INSERT INTO post_votes() VALUES()");
        $votes_id = $this->lastInsertId();
        $this->query("INSERT INTO questions(question, title, author, votes) VALUES(?, ?, ?, ?)",
                     $text,
                     $title,
                     $userid,
                     $votes_id);
        $quetion_id = $this->lastInsertId();
        foreach ($tags as $tag_id) {
            $this->query("INSERT INTO question_tags(question_id, tag_id) VALUES(?, ?)",
                         $quetion_id,
                         $tag_id);
        }
        return $quetion_id;
    }
    // ---------------------------------------------------------------------------------------------
    function get_questions_from_tag($tag, $page = 0, $limit = 10) {
        $tags_query = "SELECT name FROM tags LEFT JOIN question_tags qt ON tags.id = ".
                      "qt.tag_id WHERE question_id ";
        $questions = $this->query(self::$query_with_votes . ", question FROM questions q WHERE ? in " .
                                        "($tags_query = q.id)", $tag);
        foreach ($questions as &$question) {
            $question['tags'] = array_pluck($this->query("$tags_query = ?", $question['id']), 'name');
        }
        return $questions;
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
    function delete_question($question_id) {
        $votes = $this->query("SELECT votes FROM quetions WHERE id = ?", $question_id);
        if (count($votes) == 1) {
            $votes = $votes[0]['votes'];
            $this->query("DLETE FROM votes WHERE connection = ?", $votes);
            $this->query("DLETE FROM post_votes WHERE id = ?", $votes);
            $this->query("DELETE FROM answers WHERE question_id = ?", $question_id);
            $this->query("DELETE FROM question_tags WHERE question_id = ?", $question_id);
            $this->query("DELETE FROM questions WHERE id = ?", $question_id);
        }
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

try {
    $app->register("admin", "admin@jcubic.pl", "some_password", "admin");
} catch (Exception $e) {
    echo "Admin exists\n";
}

/*

   queries
   
   select id title and count of votes

   SELECT q.id, title, (SELECT count(*) FROM post_votes AS p LEFT JOIN votes ON p.id = connection WHERE up = true AND p.id = q.votes) as up_votes, (SELECT count(*) FROM post_votes AS p LEFT JOIN votes ON p.id = connection WHERE up = false AND p.id = q.votes) as down_votes FROM questions q;
   
   If you add your email you will get answers to your question in your inbox, otherwise you will need to visit
   the page again to see responses.
   
   Only registered users can answer questions
   
   Register or Login to add answer
   
   Masz problem z JavaScript lub CSS zadaj pytanie. Znasz odpowiedź na pytanie zarejestruj się i udziel odpowiedzi.
   
   Jest to wersja próbna pytań i odpowiedzi, na stronie Głównie JavaScript, możesz ją nazwać Beta.
*/


$app->login("admin", "some_password");

$app->create_tag("javascript");
$app->create_tag("css");
$app->create_tag("php");

$admin_id = $app->get_user_id("admin");
echo "admin: $admin_id\n";
//$id = $app->ask_question($admin_id, "Jak napisać kod", "foo", array("javascript", "css"));

//$app->vote($admin_id, $id, true);

print_r($app->get_questions_from_tag("css"));

?>
