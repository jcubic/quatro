CREATE TABLE IF NOT EXISTS account_types (
       id INT NOT NULL auto_increment,
       name VARCHAR(100),
       PRIMARY KEY(id)
);
CREATE TABLE IF NOT EXISTS users(
       id INT NOT NULL auto_increment,
       email VARCHAR(300),
       www VARCHAR(1000),
       bio TEXT,
       username VARCHAR(256),
       password CHAR(60) BINARY,
       type INT NOT NULL,
       PRIMARY KEY(id),
       FOREIGN KEY(type) REFERENCES account_types(id)
);
CREATE TABLE IF NOT EXISTS post_votes (
       id INT NOT NULL auto_increment,
       PRIMARY KEY(id)
);
CREATE TABLE IF NOT EXISTS votes(
       id INT NOT NULL auto_increment,
       voter INT NOT NULL,
       up BOOLEAN,
       connection INT NOT NULL,
       PRIMARY KEY(id),
       FOREIGN KEY(voter) REFERENCES users(id),
       FOREIGN KEY(connection) REFERENCES post_votes(id)
);
CREATE TABLE IF NOT EXISTS tags (
       id INT NOT NULL auto_increment,
       name VARCHAR(300),
       description TEXT,
       PRIMARY KEY(id)
);
CREATE TABLE IF NOT EXISTS questions (
       id INT NOT NULL auto_increment,
       slug VARCHAR(1000),
       title VARCHAR(1000),
       question TEXT,
       author INT NOT NULL,
       votes INT NOT NULL,
       PRIMARY KEY(id),
       FOREIGN KEY(author) REFERENCES users(id),
       FOREIGN KEY(votes) REFERENCES post_votes(id)
);
CREATE TABLE IF NOT EXISTS question_tags (
       id INT NOT NULL auto_increment,
       question_id INT NOT NULL,
       tag_id INT NOT NULL,
       PRIMARY KEY(id),
       FOREIGN KEY(question_id) REFERENCES questions(id),
       FOREIGN KEY(tag_id) REFERENCES tags(id)
);
CREATE TABLE IF NOT EXISTS answers (
       id INT NOT NULL auto_increment,
       question_id INT NOT NULL,
       answer TEXT,
       author INT NOT NULL,
       votes INT NOT NULL,
       PRIMARY KEY(id),
       FOREIGN KEY(question_id) REFERENCES questions(id),
       FOREIGN KEY(author) REFERENCES users(id),
       FOREIGN KEY(votes) REFERENCES post_votes(id)
);

CREATE TABLE IF NOT EXISTS config (
       id INT NOT NULL auto_increment,
       name VARCHAR(1000),
       value VARCHAR(1000),
       PRIMARY KEY(id)
);

