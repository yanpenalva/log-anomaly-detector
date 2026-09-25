-- Anomaly detection domain tables (SQLite).
CREATE TABLE IF NOT EXISTS analysis_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    algorithm TEXT NOT NULL,
    epsilon REAL NOT NULL,
    minimum_samples INTEGER NOT NULL,
    sample_count INTEGER NOT NULL,
    cluster_count INTEGER NOT NULL DEFAULT 0,
    anomaly_count INTEGER NOT NULL DEFAULT 0,
    started_at TEXT NOT NULL,
    finished_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_analysis_runs_started_at
    ON analysis_runs (started_at DESC);

CREATE TABLE IF NOT EXISTS log_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    analysis_run_id INTEGER NOT NULL,
    method TEXT NOT NULL,
    endpoint TEXT NOT NULL,
    status_code INTEGER NOT NULL,
    response_time REAL NOT NULL,
    request_size INTEGER NOT NULL,
    hour INTEGER NOT NULL,
    is_anomaly INTEGER NOT NULL DEFAULT 0,
    cluster INTEGER,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_log_entries_run_id
    ON log_entries (analysis_run_id);

CREATE INDEX IF NOT EXISTS idx_log_entries_run_anomaly
    ON log_entries (analysis_run_id, is_anomaly);
