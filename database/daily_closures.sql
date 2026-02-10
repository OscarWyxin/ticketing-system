-- Tabla para cierres diarios de agentes
CREATE TABLE IF NOT EXISTS daily_closures (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    closure_date DATE NOT NULL,
    summary TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_agent_date (agent_id, closure_date)
);

-- Índices para búsqueda
CREATE INDEX idx_closures_agent ON daily_closures(agent_id);
CREATE INDEX idx_closures_date ON daily_closures(closure_date);
