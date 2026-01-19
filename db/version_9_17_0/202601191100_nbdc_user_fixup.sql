/*
Function to allow migration from old Drupal instance to new, with the CMS user
IDs used to link users to locations changing. Callde from hook_user_save().

For migration from the Biodiversity Ireland monitoring portal (NBDC).
*/
CREATE OR REPLACE FUNCTION f_process_nbdc_ireland_user(
  param_website_id integer,
  param_user_id integer,
  param_cms_user_id integer
  )
RETURNS boolean
LANGUAGE 'plpgsql'
AS $BODY$
BEGIN

  INSERT INTO nbdc_ireland.cms_user_mappings
  SELECT l.old_cms_user_id, param_cms_user_id, param_website_id
  FROM nbdc_ireland.old_cms_user_id_lookup l
  WHERE l.new_user_id=param_user_id;

  UPDATE location_attribute_values ilav
  SET location_attribute_id=234,
    int_value=m.cms_user_id
  FROM nbdc_ireland.cms_user_mappings m, locations il
  WHERE il.iid=ilav.location_id
  AND ilav.int_value=m.old_cms_user_id
  AND ilav.location_attribute_id=389
  AND ((il.location_type_id IN (777, 778) AND m.website_id=118)
    OR (il.location_type_id IN (24286, 24287) AND m.website_id=164))
  AND m.new_cms_user_id=param_cms_user_id;

  RETURN TRUE;

END;
$BODY$;