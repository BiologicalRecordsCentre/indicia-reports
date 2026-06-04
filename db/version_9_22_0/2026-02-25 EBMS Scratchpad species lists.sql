/* Script that syncs the new scratchpad list approach to UKBMS country lists with the old taxon attribute approach. */
CREATE OR REPLACE FUNCTION ebms_sync_lists_to_scratchpads(
	)
    RETURNS boolean
	LANGUAGE 'plpgsql'
AS $BODY$
BEGIN

-- Find all the lists we want to exist.
drop table if exists lists_to_create;
create temporary table lists_to_create as
select distinct on (ttlacaption.title) 'EBMS Country List - ' || ttla.caption as list_title, ttla.caption as country_code, l.id as country_location_id, l.name as country_location_name, ttla.deleted;
from taxa_taxon_list_attributes ttla
left join locations l on l.code=ttla.caption and l.deleted=false and l.location_type_id=16516
where ttla.id in (select taxa_taxon_list_attribute_id from taxon_lists_taxa_taxon_list_attributes where taxon_list_id in (251,261) and deleted=false)
and termlist_id=816
order by ttla.deleted asc;

update lists_to_create set country_location_name='Bonaire' where country_code='BQ: BO';
update lists_to_create set country_location_name='Saba' where country_code='BQ: SA';
update lists_to_create set country_location_name='Sint Eustatius' where country_code='BQ: SE';
update lists_to_create set country_location_name='Spain mainland' where country_code='ES';
update lists_to_create set country_location_name='Canary Islands' where country_code='ES: CA';
update lists_to_create set country_location_name='Europe' where country_code='Europe';
update lists_to_create set country_location_name='United Kingdom' where country_code='GB';
update lists_to_create set country_location_name='Portugal mainland' where country_code='PT';
update lists_to_create set country_location_name='Madeira Islands' where country_code='PT: MA';
update lists_to_create set country_location_name='Azores' where country_code='PT: AZ';
update lists_to_create set country_location_name='Saint Helena' where country_code='SH: HL';
update lists_to_create set country_location_name='Turkey, Asia' where country_code='TRA';
update lists_to_create set country_location_name='Turkey, Europe' where country_code='TR';

-- Update the descriptions for existing lists.
update scratchpad_lists sl
set description='List generated for managing the species list for ' || lc.country_code || coalesce(' (' || lc.country_location_name || ').', '.')
from lists_to_create lc
where lc.list_title=sl.title
and sl.scratchpad_type_id=24535 and sl.deleted=false
and sl.description<>'List generated for managing the species list for ' || lc.country_code || coalesce(' (' || lc.country_location_name || ').', '.')
and lc.deleted=false;

-- Create the lists, but skip any that already exist.
insert into scratchpad_lists(title, description, entity, created_on, created_by_id, updated_on, updated_by_id, website_id, scratchpad_type_id)
select lc.list_title, 'List generated for managing the species list for ' || lc.country_code || coalesce(' (' || lc.country_location_name || ').', '.'),
  'taxa_taxon_list', now(), 3, now(), 3, 118, 24535
from lists_to_create lc
left join scratchpad_lists sl on sl.title=lc.list_title and sl.scratchpad_type_id=24535 and sl.deleted=false
where sl.id is null
and lc.deleted=false;

-- Find the taxa/country links and codes.
drop table if exists proposed_links;
create temporary table proposed_links as
select cttl.id as taxa_taxon_list_id, cttl.taxon, ttla.caption, t.term as presence_code
from cache_taxa_taxon_lists cttl
join taxa_taxon_list_attribute_values ttlav on ttlav.taxa_taxon_list_id=cttl.id and ttlav.deleted=false
join cache_termlists_terms t on t.id=ttlav.int_value
join taxa_taxon_list_attributes ttla on ttla.id=ttlav.taxa_taxon_list_attribute_id and ttla.termlist_id=816 and ttla.deleted=false
where cttl.taxon_list_id in (251,260,261,265)
-- Skip absent, no point.
and t.term<>'A';

update scratchpad_list_entries sle
set metadata=('{"presence_code":"' || pl.presence_code || '"}')::json,
  updated_on=now(), updated_by_id=3
from proposed_links pl
join scratchpad_lists sl on sl.title='EBMS Country List - ' || pl.caption and sl.scratchpad_type_id=24535
where sle.entry_id=pl.taxa_taxon_list_id and sle.scratchpad_list_id=sl.id
and sle.deleted=false
and sle.metadata::text<>('{"presence_code":"' || pl.presence_code || '"}');

-- Delete entries that are not in the proposed list.
update scratchpad_list_entries sle
set deleted=true, updated_on=now(), updated_by_id=3
where id in (
	select sle.id from scratchpad_list_entries sle
	  join scratchpad_lists sl on sl.id=sle.scratchpad_list_id
	  and sl.scratchpad_type_id=24535
	left join proposed_links pl on 'EBMS Country List - ' || pl.caption=sl.title
	  and pl.taxa_taxon_list_id=sle.entry_id
	where sle.deleted=false
	and pl.taxa_taxon_list_id is null
);

-- Add any that are missing.
insert into scratchpad_list_entries(scratchpad_list_id, entry_id, metadata, created_on, created_by_id, updated_on, updated_by_id)
select sl.id, pl.taxa_taxon_list_id, ('{"presence_code":"' || pl.presence_code || '"}')::json, now(), 3, now(), 3
from proposed_links pl
left join scratchpad_lists sl on sl.title='EBMS Country List - ' || pl.caption and sl.scratchpad_type_id=24535 and sl.deleted=false
left join scratchpad_list_entries exist on exist.scratchpad_list_id=sl.id
  and exist.entry_id=pl.taxa_taxon_list_id
where exist.id is null;

return true;

END;
$BODY$;

SELECT ebms_sync_lists_to_scratchpads();

-- Example query to find all available lists:
select sl.id as scratchpad_list_id, sl.title, sl.description,
  coalesce('[' || string_agg(distinct json_build_object('group_id', g.id, 'group_title', g.title)::text, ',') filter (where g.id is not null) || ']', '[]') as group_info,
  coalesce('[' || string_agg(distinct json_build_object('location_id',l.id, 'location_name', l.name)::text, ',') filter (where l.id is not null) || ']', '[]') as location_info,
  case when sl.title like 'EBMS Country List - %' then replace(sl.title, 'EBMS Country List - ', '') else null end as region_code,
  count(distinct cttl.taxon_meaning_id) as taxa_count,
  '[' || string_agg(distinct cttl.taxon_group_id::text, ',') || ']' as species_groups
from scratchpad_lists sl
left join groups_scratchpad_lists gsl on gsl.scratchpad_list_id=sl.id and gsl.deleted=false
left join groups g on g.id=gsl.group_id and g.deleted=false
left join locations_scratchpad_lists lsl on lsl.scratchpad_list_id=sl.id and lsl.deleted=false
left join locations l on l.id=lsl.location_id and l.deleted=false
left join scratchpad_list_entries sle on sle.scratchpad_list_id=sl.id and sle.deleted=false
left join cache_taxa_taxon_lists cttl on cttl.id=sle.entry_id
where sl.deleted=false
and sl.website_id=118
-- Miscellanous lists, or EBMS region lists.
and sl.scratchpad_type_id in (22150, 24535)
--and g.id=2963
--and sl.id=12093
group by sl.id, sl.title, sl.description;
