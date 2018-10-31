CREATE table IF NOT EXISTS account_types (
       id INT NOT NULL auto_increment,
       name VARCHAR(100),
       PRIMARY KEY(id)
);
CREATE table IF NOT EXISTS users(
       id INT NOT NULL auto_increment,
       email VARCHAR(256),
       username VARCHAR(256),
       password CHAR(60) BINARY,
       type INT NOT NULL,
       PRIMARY KEY(id),
       FOREIGN KEY(type) REFERENCES account_types(id)
);
CREATE table IF NOT EXISTS questions (
       id INT NOT NULL auto_increment,
       question TEXT,
       author INT NOT NULL,
       PRIMARY KEY(id),
       FOREIGN KEY(author) REFERENCES users(id)
);
CREATE table IF NOT EXISTS answers (
       id INT NOT NULL auto_increment,
       question_id INT NOT NULL,
       answer TEXT,
       author INT NOT NULL,
       PRIMARY KEY(id),
       FOREIGN KEY(question_id) REFERENCES questions(id),
       FOREIGN KEY(author) REFERENCES users(id)
);



