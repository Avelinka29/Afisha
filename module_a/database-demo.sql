-- Выбрать базу проекта.
USE profskills;
SHOW TABLES;

-- Пользователи и их курс. Хеши паролей для демонстрации не нужны.
SELECT id, name, email, faculty, course, role, blocked, email_verified_at
FROM users ORDER BY id;

-- Права представителей привязаны к конкретному сообществу.
SELECT u.name, u.email, c.name AS community, cu.verified
FROM club_users cu
JOIN users u ON u.id = cu.user_id
JOIN clubs c ON c.id = cu.club_id
ORDER BY u.id, c.id;

-- События и их авторы.
SELECT e.id, e.title, c.name AS community, e.status, e.starts_at,
       e.capacity, u.name AS author
FROM events e
JOIN clubs c ON c.id = e.club_id
JOIN users u ON u.id = e.created_by
ORDER BY e.starts_at;

-- Кто записан на мероприятия.
SELECT u.name AS student, e.title AS event, r.status, r.created_at
FROM registrations r
JOIN users u ON u.id = r.user_id
JOIN events e ON e.id = r.event_id
ORDER BY r.created_at DESC;

-- Обе реакции студента хранятся отдельными записями.
SELECT u.email, e.title, r.type, r.created_at
FROM reactions r
JOIN users u ON u.id = r.user_id
JOIN events e ON e.id = r.event_id
ORDER BY r.id DESC;

-- Уведомления и ссылка на связанное событие.
SELECT u.email, n.type, n.message, n.payload, n.read_at, n.created_at
FROM notifications n JOIN users u ON u.id = n.user_id
ORDER BY n.id DESC;

-- Привязка Google к локальному профилю, без вывода OAuth-секретов.
SELECT u.email, g.created_at AS google_linked_at
FROM google_accounts g JOIN users u ON u.id = g.user_id;
