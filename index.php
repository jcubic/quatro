<?php

require_once('vendor/autoload.php');
require_once('q-config.php');

error_reporting(E_ERROR | E_WARNING | E_PARSE | E_NOTICE);
ini_set('display_errors', 'On');

// -------------------------------------------------------------------------------------------------
function slug($title, $replace=array(), $delimiter='-') {
    setlocale(LC_ALL, 'en_US.UTF8');
    if (!empty($replace)) {
        $str = str_replace((array)$replace, ' ', $title);
    }
    $clean = iconv('UTF-8', 'ASCII//TRANSLIT', $title);
    $clean = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $clean);
    $clean = strtolower(trim($clean, '-'));
    $clean = preg_replace("/[\/_|+ -]+/", $delimiter, $clean);
    return $clean;
}

// -------------------------------------------------------------------------------------------------
function redirect($request, $response, $uri) {
    if (is_string($uri)) {
        $url = baseURI($request) . $uri;
    } else if (get_class($uri) == "Slim\Http\Uri") {
        $url = url($uri);
    }
    return $response->withStatus(302)->withHeader('Location', $url);
}

// -------------------------------------------------------------------------------------------------
function baseURI($request) {
    $uri = $request->getUri();
    return $uri->getBasePath();
}

// -------------------------------------------------------------------------------------------------
function load_gettext_domains($root, $lang) {
    if (!preg_match("%" . DIRECTORY_SEPARATOR . "$%", $root)) {
        $root .= DIRECTORY_SEPARATOR;
    }
    $lang = preg_replace("/\.[^.]+$/", "", $lang);
    $path = $root . DIRECTORY_SEPARATOR .
            $lang . DIRECTORY_SEPARATOR . "LC_MESSAGES";
    if (file_exists($path)) {
        foreach (scandir($path) as $file) {
            if (preg_match("/(.*)\.mo$/", $file, $match)) {
                bindtextdomain($match[1], $root);
            }
        }
    }
}

// -------------------------------------------------------------------------------------------------
function compress($html) {
    $search = array(
        '/\n/',            // replace end of line by a space
        '/\>[^\S ]+/s',        // strip whitespaces after tags, except space
        '/[^\S ]+\</s',        // strip whitespaces before tags, except space
        '/(\s)+/s'        // shorten multiple whitespace sequences
    );
    $replace = array(
        '',
        '>',
        '<',
        '\\1'
    );
    $in_script = false;
    $array = preg_split("/(<\/?\s*script[^>]*>)/i", $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $outout = array();
    foreach ($array as $html) {
        if (preg_match("/^<\/?\s*script/i", $html)) {
            $in_script = !$in_script;
            $output[] = $html;
        } else if (!$in_script) {
            $output[] = preg_replace($search, $replace, $html);
        } else {
            $output[] = $html;
        }
    }
    return preg_replace('/(<!\s*DOCTYPE[^>]+>)/', "\\1\n", implode("", $output));
}

// -------------------------------------------------------------------------------------------------
function tidy($html, $options = array()) {
    $config = array_merge(array(
        'indent' => true,
        'input-xml' => true,
        'output-xhtml' => true,
        'wrap' => 200,
        'merge-spans' => false,
        'merge-divs' => false
    ), $options);
    $tidy = new tidy();
    $tidy->parseString($html, $config, 'utf8');
    $tidy->cleanRepair();
    return $tidy;
}

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
class QArtoError extends Exception {
}

class QArto {
    static $query_with_votes = "SELECT q.id, title, (SELECT count(*) FROM post_votes AS p LEFT JOIN votes ON " .
                               "p.id = connection WHERE up = true AND p.id = q.votes) as up_votes, (SELECT ".
                               "count(*) FROM post_votes AS p LEFT JOIN votes ON p.id = connection WHERE up ".
                               "= false AND p.id = q.votes) as down_votes ";
    static $tags_query = "SELECT name FROM tags LEFT JOIN question_tags qt ON tags.id = ".
                         "qt.tag_id WHERE question_id ";

    function __construct($lang = NULL) {
        header_remove("X-Powered-By");
        header("X-Frame-Options: Deny");
        header('X-Content-Type-Options: nosniff');
        $container = new \Slim\Container;

        $this->db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                            DB_USER,
                            DB_PASSWORD);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // templates
        $this->root = __DIR__ . DIRECTORY_SEPARATOR;
        $templates_dir = $this->root . 'templates' . DIRECTORY_SEPARATOR;

        $this->config = new stdClass();
        if (count($this->query("SHOW TABLES LIKE 'config'")) == 1) {
            foreach ($this->query("SELECT name, value FROM config") as $options) {
                $name = $options['name'];
                $this->config->$name = $options['value'];
            }
        }

        if (isset($this->config->template) && $this->config->template != 'default') {
            $template = array(
                $templates_dir . $this->config->template,
                $templates_dir . 'default'
            );
        } else {
            $template = $templates_dir . 'default';
        }

        $this->loader = new Twig_Loader_Filesystem($template);
        $this->twig = new Twig_Environment($this->loader);

        $app = $this;
        $container['errorHandler'] = $container['phpErrorHandler'] = function ($c) use ($app) {
            return function($request, $response, $exception) use ($c, $app) {
                $stack = $exception->getTraceAsString();
                $message = $exception->getMessage();
                $type = get_class($exception);
                $line = $exception->getLine();
                $file = $exception->getFile();
                return $c['response']->withStatus(500)
                                     ->withHeader('Content-Type', 'text/plain')
                                     ->write($message . "\n" . $stack);
            };
        };
        $container['settings']['displayErrorDetails'] = true;
        if (!$lang) {
            $lang = DEFAULT_LOCALE;
        }
        if (!preg_match("/utf-?8$/", $lang)) {
            $lang .= ".utf8";
        }
        putenv("LC_ALL=$lang");
        setlocale(LC_ALL, $lang);
        load_gettext_domains($this->root . "locale", $lang);
        $this->twig->addFunction(new Twig_Function('_', function($text) {
            return _($text);
        }));

        $this->app = new \Slim\App($container);
    }
    // ---------------------------------------------------------------------------------------------
    function install() {
        $queries = clean(explode(";", file_get_contents("create.sql")));
        foreach ($queries as $query) {
            $this->query($query);
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
            throw new QArtoError("user already exists");
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
    function user_vote($userid, $post_id, $table = 'questions') {
        $ret = $this->query("SELECT up, v.id as vote_id FROM votes v LEFT JOIN post_votes " .
                            "p ON p.id = connection LEFT JOIN  $table ON votes = p.id WHERE " .
                            "$table.id = ?",
                            $post_id);
        if (count($ret) != 0) {
            return $ret[0];
        }
    }
    // ---------------------------------------------------------------------------------------------
    function vote($userid, $post_id, $vote, $table = 'questions') {
        $user_vote = $this->user_vote($userid, $post_id, $table);
        if ($user_vote) {
            if ($user_vote['up'] == $vote) {
                throw new QArtoError("You already voted");
            }
            $this->query("UPDATE votes SET up = ? WHERE id = ?", (int)$vote, $user_vote['vote_id']);
        } else {
            $this->query("INSERT INTO votes(voter, up, connection) SELECT ?, ?, votes FROM $table WHERE " .
                         "id = ?",
                         $userid,
                         $vote,
                         $post_id);
        }
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
    function get_tags($question_id) {
        return array_pluck($this->query(self::$tags_query . " = ?", $question_id), 'name');
    }
    // ---------------------------------------------------------------------------------------------
    function get_questions_from_tag($tag, $page = 0, $limit = 10) {

        $questions = $this->query(self::$query_with_votes . ", question FROM questions q WHERE ? in " .
                                        "(" . self::$tags_query . " = q.id)", $tag);
        foreach ($questions as &$question) {
            $question['tags'] = $this->get_tags($question['id']);
        }
        return $questions;
    }
    // ---------------------------------------------------------------------------------------------
    function get_question($id) {
        $result = $this->query(self::$query_with_votes . ", question, slug, (SELECT username FROM " .
                                     "users u WHERE q.author = u.id) AS author FROM questions q" .
                                     " WHERE q.id = ?",
                               $id);
        if (count($result) == 1) {
            $result = $result[0];
            $result['tags'] = $this->get_tags($result['id']);
            return $result;
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
    function render($request, $page, $data = array()) {
        $uri = $request->getUri();
        $base = $uri->getBasePath();
        $path = preg_replace(
            "%" . __DIR__ . "%",
            "",
            $this->loader->getSourceContext($page)->getPath()
        );
        $path = preg_replace('%/' . $page . '$%', '', $path);
        $html = $this->twig->render($page, array_merge(array(
            "path" => $base . $path,
            "root" => $uri->getScheme() . "://" . $uri->getAuthority() . $base,
            "now" => date("Y-m-d H:i:s"),
        ), $data));
        if (COMPRESS) {
            if (TIDY) {
                $html = tidy($html, array('wrap' => -1, 'indent' => false));
            }
            $html = compress($html);
        } elseif (TIDY) {
            $html = tidy($html, TIDY_OPTIONS);
        }
        return $html;
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




$app = new QArto();


$app->get('/', function($request, $response, $args) use ($app) {
    $response = $response->withHeader('Content-Type', 'text/plain');
    if (isset($_SESSION['user'])) {
        $response->write($_SESSION['user'] . " " . $_SESSION['role'] . "\n");
    }
    $response->write(($app->install() ? 'true' : 'false') . "\n");

    try {
        $app->register("admin", "admin@jcubic.pl", "some_password", "admin");
    } catch (QArtoError $e) {
        $response->write("Admin exists\n");
    }


    $app->login("admin", "some_password");

    $app->create_tag("javascript");
    $app->create_tag("css");
    $app->create_tag("php");

    $admin_id = $app->get_user_id("admin");
    $response->write("admin: $admin_id\n");
    //$id = $app->ask_question($admin_id, "Jak napisać kod", "foo", array("javascript", "css"));

    $question = $app->get_questions_from_tag("css");

    $app->query("UPDATE questions SET slug = ? WHERE id = ?", slug($question[0]['title']), $question[0]['id']);

    try {
        $app->vote($admin_id, $question[0]['id'], false);
    } catch (QArtoError $e) {
        $response->write("Already Voted\n");
    }
    $response->write(json_encode($question, JSON_PRETTY_PRINT));
    return $response;
});

$app->get('/q/{id}/{slug}', function($request, $response, $args) use ($app) {
    $body = $response->getBody();
    $question = $app->get_question($args['id']);
    if ($question) {
        $url = "/q/" . $args['id'] . "/" . $question['slug'];
        if ($args['slug'] != $question['slug']) {
            return redirect($request, $response, $url);
        }
        $body->write($app->render($request, "question.html", array_merge(array(
            'canonical' => $url
        ), $question)));
        $body->write("<!-- " . json_encode($question, JSON_PRETTY_PRINT) . " -->");
        return $response;
    } else {
        throw new \Slim\Exception\NotFoundException($request, $response);
    }
});

$app->run();

?>
