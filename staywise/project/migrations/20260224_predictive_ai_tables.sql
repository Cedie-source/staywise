-- ============================================================
-- StayWise Predictive AI & Proactive Notifications Schema
-- Migration: 20260224_predictive_ai_tables.sql
-- ============================================================

-- 1. AI Predictions: predicted maintenance issues, risks per unit
CREATE TABLE IF NOT EXISTS ai_predictions (
    prediction_id   INT AUTO_INCREMENT PRIMARY KEY,
    unit_number     VARCHAR(50)   NULL,
    tenant_id       INT           NULL,
    category        VARCHAR(100)  NOT NULL DEFAULT 'general',   -- plumbing, electrical, structural, pest, appliance, etc.
    risk_level      ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    prediction_text TEXT          NOT NULL,
    confidence_score DECIMAL(5,2) NOT NULL DEFAULT 50.00,       -- 0-100
    based_on        TEXT          NULL,                          -- JSON: complaint IDs / pattern data
    recommended_action TEXT       NULL,
    status          ENUM('active','acknowledged','resolved','dismissed') NOT NULL DEFAULT 'active',
    predicted_date  DATE          NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pred_unit (unit_number),
    INDEX idx_pred_status (status),
    INDEX idx_pred_risk (risk_level),
    INDEX idx_pred_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. AI Notifications: proactive alerts for tenants and admins
CREATE TABLE IF NOT EXISTS ai_notifications (
    notification_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT           NOT NULL,
    type            ENUM('prediction','maintenance','payment','advisory','trend') NOT NULL DEFAULT 'advisory',
    title           VARCHAR(255)  NOT NULL,
    message         TEXT          NOT NULL,
    priority        ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    is_read         TINYINT(1)    NOT NULL DEFAULT 0,
    action_url      VARCHAR(255)  NULL,
    related_id      INT           NULL,  -- optional FK to prediction_id or complaint_id
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id, is_read),
    INDEX idx_notif_type (type),
    INDEX idx_notif_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. AI Insights: aggregated trend analysis results
CREATE TABLE IF NOT EXISTS ai_insights (
    insight_id      INT AUTO_INCREMENT PRIMARY KEY,
    insight_type    ENUM('trend','pattern','anomaly','recommendation') NOT NULL DEFAULT 'trend',
    category        VARCHAR(100)  NULL,
    title           VARCHAR(255)  NOT NULL,
    description     TEXT          NOT NULL,
    data_json       TEXT          NULL,   -- JSON with chart data, metrics, etc.
    severity        ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    period_start    DATE          NULL,
    period_end      DATE          NULL,
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_insight_type (insight_type),
    INDEX idx_insight_active (is_active),
    INDEX idx_insight_severity (severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Maintenance Patterns: detected recurring issue patterns
CREATE TABLE IF NOT EXISTS maintenance_patterns (
    pattern_id              INT AUTO_INCREMENT PRIMARY KEY,
    category                VARCHAR(100)  NOT NULL,
    unit_number             VARCHAR(50)   NULL,
    occurrence_count        INT           NOT NULL DEFAULT 1,
    avg_resolution_days     DECIMAL(5,1)  NULL,
    last_occurrence         DATE          NULL,
    pattern_description     TEXT          NULL,
    recurrence_interval_days INT          NULL,   -- detected avg days between occurrences
    next_predicted_date     DATE          NULL,
    severity                ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    created_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pattern_unit (unit_number),
    INDEX idx_pattern_category (category),
    INDEX idx_pattern_next (next_predicted_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Chat History: persist conversation for context-aware AI
CREATE TABLE IF NOT EXISTS chat_history (
    message_id  INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT           NOT NULL,
    role        ENUM('user','assistant','system') NOT NULL,
    message     TEXT          NOT NULL,
    intent      VARCHAR(100)  NULL,
    context_data TEXT         NULL,   -- JSON
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Analysis Run Log: track when automated analysis last ran
CREATE TABLE IF NOT EXISTS ai_analysis_log (
    log_id          INT AUTO_INCREMENT PRIMARY KEY,
    analysis_type   VARCHAR(100)  NOT NULL,   -- 'pattern_detection', 'prediction_generation', 'trend_analysis'
    status          ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
    records_processed INT        NOT NULL DEFAULT 0,
    insights_generated INT       NOT NULL DEFAULT 0,
    details         TEXT         NULL,
    started_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    TIMESTAMP    NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
