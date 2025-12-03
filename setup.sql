CREATE DATABASE IF NOT EXISTS employees_dashboard;
USE employees_dashboard;

CREATE TABLE IF NOT EXISTS employees (
    emp_no VARCHAR(10) PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name  VARCHAR(50) NOT NULL,
    birth_date DATE NOT NULL,
    dept_no    VARCHAR(10),
    title_id   INT,
    role ENUM('employee','manager','ceo') NOT NULL DEFAULT 'employee',
    password_hash VARCHAR(255) NULL
);

CREATE TABLE IF NOT EXISTS departments (
    dept_no   VARCHAR(10) PRIMARY KEY,
    dept_name VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS titles (
    id    INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS salaries (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    emp_no    VARCHAR(10) NOT NULL,
    salary    DECIMAL(10,2) NOT NULL,
    from_date DATE NOT NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (emp_no) REFERENCES employees(emp_no) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS department_managers (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    dept_no      VARCHAR(10) NOT NULL,
    manager_name VARCHAR(100) NOT NULL,
    FOREIGN KEY (dept_no) REFERENCES departments(dept_no) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emp_no VARCHAR(10) NOT NULL,
    task_title VARCHAR(100) NOT NULL,
    task_description TEXT,
    due_date DATE,
    status ENUM('pending','in_progress','done') NOT NULL DEFAULT 'pending',
    FOREIGN KEY (emp_no) REFERENCES employees(emp_no) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS initial_passwords (
    id INT AUTO_INCREMENT PRIMARY KEY,
    emp_no VARCHAR(10) NOT NULL,
    plain_password VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO departments (dept_no, dept_name) VALUES
('d001', 'Web Development'),
('d002', 'Game Studio'),
('d003', 'UI/UX Lab'),
('d004', 'Platform & DevOps'),
('d005', 'Business & Operations')
ON DUPLICATE KEY UPDATE dept_name = VALUES(dept_name);

INSERT INTO titles (title) VALUES
('Frontend Developer'),
('Backend Developer'),
('Full-Stack Developer'),
('Game Programmer'),
('Game Designer'),
('Level Designer'),
('UI/UX Designer'),
('Product Designer'),
('3D Artist'),
('Technical Artist'),
('QA Tester'),
('DevOps Engineer'),
('Project Manager'),
('Producer'),
('Community Manager'),
('Technical Writer'),
('Data Analyst'),
('Art Director'),
('Creative Director'),
('Studio Manager');

INSERT INTO employees (emp_no, first_name, last_name, birth_date, dept_no, title_id, role)
VALUES ('10000', 'Icy', 'Phoenix', '1995-01-01', 'd005', 19, 'ceo')
ON DUPLICATE KEY UPDATE
first_name = VALUES(first_name),
last_name  = VALUES(last_name),
birth_date = VALUES(birth_date),
dept_no    = VALUES(dept_no),
title_id   = VALUES(title_id),
role       = VALUES(role);

INSERT INTO salaries (emp_no, salary, from_date, is_current)
VALUES ('10000', 150000, CURDATE(), 1)
ON DUPLICATE KEY UPDATE
salary = VALUES(salary),
from_date = VALUES(from_date),
is_current = VALUES(is_current);
