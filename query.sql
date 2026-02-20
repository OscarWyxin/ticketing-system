-- Restaurar backlog para tickets que tienen backlog_type y están asignados a revisores (Alfonso=3, Alicia=6)
-- o que no tienen agente real asignado
UPDATE tickets 
SET backlog = 1 
WHERE backlog_type IS NOT NULL 
  AND backlog_type != ''
  AND status NOT IN ('resolved', 'closed')
  AND (assigned_to IN (3, 6) OR assigned_to IS NULL);

-- Ver resultado
SELECT id, ticket_number, SUBSTRING(title,1,40) as title, backlog, backlog_type, assigned_to, status 
FROM tickets 
WHERE backlog_type IS NOT NULL AND backlog_type != ''
ORDER BY backlog DESC, id DESC;
