-- Verificar backlog actual
SELECT 
  CASE WHEN backlog = 1 THEN 'EN BACKLOG' ELSE 'FUERA' END as estado,
  COUNT(*) as cantidad
FROM tickets 
WHERE backlog_type IS NOT NULL AND backlog_type != ''
GROUP BY backlog;
