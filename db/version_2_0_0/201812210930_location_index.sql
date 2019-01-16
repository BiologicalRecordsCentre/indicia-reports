-- An index to speed up indexing of locations/samples data.
CREATE INDEX ix_locations_boundary_geom_indexed
  ON locations
  USING gist
  (boundary_geom)
  WHERE location_type_id IN (15, 1370, 2188, 4839, 4980, 4996, 1103)
  AND deleted=false;