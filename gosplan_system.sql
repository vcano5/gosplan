CREATE TABLE gosplan_system_users (
    id            CHAR(36)     PRIMARY KEY,
    username      VARCHAR(255) UNIQUE NOT NULL,
    email         VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE gosplan_system_tenant_dbs (
    id         CHAR(36)     PRIMARY KEY,
    userId     CHAR(36)     NOT NULL REFERENCES gosplan_system_users(id),
    db_name    VARCHAR(255) NOT NULL,   -- 'gosplan_uuid'
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE gosplan_system_permissions (
    id         CHAR(36)    PRIMARY KEY,
    userId     CHAR(36)    NOT NULL REFERENCES gosplan_system_users(id),
    project    VARCHAR(255) NOT NULL,  -- 'todoapp' | '*'
    `table`    VARCHAR(255) NOT NULL,  -- 'tasks'   | '*'
    mask       TINYINT(4)  NOT NULL DEFAULT 0,
    created_at TIMESTAMP   DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_permission (userId, project, `table`)
);

CREATE TABLE gosplan_system_tokens (
    id            CHAR(36)     PRIMARY KEY,
    userId        CHAR(36)     NOT NULL REFERENCES gosplan_system_users(id),
    refresh_hash  VARCHAR(255) NOT NULL,
    expires_at    TIMESTAMP    NOT NULL,
    revoked_at    TIMESTAMP    NULL,
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE gosplan_system_logs (
    id         CHAR(36)     PRIMARY KEY,
    userId     CHAR(36)     NULL,
    project    VARCHAR(255) NULL,
    `table`    VARCHAR(255) NULL,
    action     VARCHAR(50)  NOT NULL,
    resourceId VARCHAR(255) NULL,
    ip         VARCHAR(45)  NOT NULL,
    status     SMALLINT     NOT NULL,
    duration   INT          NULL,     -- ms
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);