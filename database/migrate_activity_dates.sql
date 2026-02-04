-- =====================================================
-- MIGRACIÓN: Agregar campos de fecha a project_activities
-- Ejecutar en MySQL para agregar los nuevos campos
-- =====================================================

-- Agregar campos de fecha a la tabla project_activities
ALTER TABLE project_activities 
ADD COLUMN IF NOT EXISTS start_date DATE DEFAULT NULL AFTER video_url,
ADD COLUMN IF NOT EXISTS end_date DATE DEFAULT NULL AFTER start_date;

-- Verificación
SELECT 'Migración completada: campos start_date y end_date agregados a project_activities' as resultado;
