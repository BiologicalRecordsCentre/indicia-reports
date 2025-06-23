<?php

/**
 * @file
 * Prepares a table that simplifies querying for BeachTrack badges.
 *
 * Indicia, the OPAL Online Recording Toolkit.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see http://www.gnu.org/licenses/gpl.html.
 *
 * @license http://www.gnu.org/licenses/gpl.html GPL
 * @link https://github.com/indicia-team/warehouse/
 */

function get_beachtrack_badges_metadata() {
  return array(
    'frequency' => '1 day',
    'autodrop' => FALSE,
  );
}

function get_beachtrack_badges_query() {
  return <<<'QRY'
    CREATE TABLE IF NOT EXISTS custom_cache_tables.beachtrack_badges (
      user_id integer NOT NULL,
      badge text NOT NULL,
      description text NOT NULL,
      metric float,
      metric_meaning text,
      awarded_on timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_on timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS ix_beachtrack_badges_user_id_date ON custom_cache_tables.beachtrack_badges(user_id, updated_on);

    DROP TABLE IF EXISTS track_data;

    SELECT s.id,
      created_by_id,
      s.location_name,
      snf.attrs_json,
      date_start,
      EXTRACT(month FROM date_start)*100+EXTRACT(day FROM date_start) as mmdd
    INTO TEMPORARY track_data
    FROM samples s
    JOIN cache_samples_nonfunctional snf ON snf.id=s.id
    WHERE s.survey_id=721
    AND s.parent_id IS NULL
    AND s.deleted=false;

    CREATE INDEX ix_track_data_mmdd ON track_data(mmdd);

    DROP TABLE IF EXISTS beachtrack_badges_build;

    /********/
    SELECT created_by_id as user_id,
      'Early Bird' as badge,
      '5 walks started before 08:00' as description,
      COUNT(DISTINCT id) as metric,
      'Total number of walks started before 08:00' as metric_meaning
    INTO TEMPORARY beachtrack_badges_build
    FROM track_data
    WHERE SUBSTRING(attrs_json->>'1715' FROM 12 FOR 5) <= '08:00'
    GROUP BY created_by_id
    HAVING COUNT(DISTINCT id) > 5
    UNION ALL
    /********/
    SELECT created_by_id,
      'Sperm Whale migration',
      'Walked as far as a Sperm Whale migrates',
      SUM((attrs_json->>'1718')::float),
      'Total distance walked in km'
    FROM track_Data
    GROUP BY created_by_id
    HAVING SUM((attrs_json->>'1718')::float)>4000
    UNION ALL
    /********/
    SELECT created_by_id,
      'Porpoise' as badge,
      'Walked the same beach at least 5 times',
      MAX(value),
      'Maximum number of walks on the same beach'
    FROM (
      SELECT created_by_id,
        COUNT(DISTINCT id) AS value
      FROM track_data
      GROUP BY created_by_id, location_name
      HAVING COUNT(DISTINCT id)>5
      ORDER BY created_by_id, count(id) DESC
    ) AS subquery
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Pilot Whale',
      'Walked the same beach at least 25 times',
      MAX(value),
      'Maximum number of walks on the same beach'
    FROM (
      SELECT created_by_id,
        COUNT(DISTINCT id) AS value
      FROM track_data
      GROUP BY created_by_id, location_name
      HAVING COUNT(DISTINCT id)>25
      ORDER BY created_by_id, count(id) DESC
    ) AS subquery
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT DISTINCT ON (created_by_id) created_by_id,
      'Minke Whale',
      'Walked the same beach at least 50 times',
      MAX(value),
      'Maximum number of walks on the same beach'
    FROM (
      SELECT created_by_id,
        COUNT(id) as value
      FROM track_data
      GROUP BY created_by_id, location_name
      HAVING COUNT(DISTINCT id)>50
      ORDER BY created_by_id, count(id) DESC
    ) AS subquery
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Going the distance',
      'A single track of at least 5km',
      MAX((attrs_json->>'1718')::float),
      'Maximum distance of a single track for this user'
    FROM track_data
    GROUP BY created_by_id
    HAVING MAX((attrs_json->>'1718')::float)>=5
    UNION ALL
    /********/
    SELECT created_by_id,
      'Time to Track',
      'At least one month with over 10 hours tracked',
      COUNT(*),
      'Number of months with over 10 hours tracked'
    FROM (
      SELECT created_by_id
      FROM track_data
      GROUP BY created_by_id, date_trunc('month', date_start)
      HAVING SUM((attrs_json->>'1717')::interval)>='10:00:00'
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Quarterly Track 1',
      'At least 91 miles walked January to March in any year',
      COUNT(*),
      'Number of times this user has achieved 91 miles in January to March for any year'
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start)
      FROM track_data
      WHERE EXTRACT('month' FROM date_start) BETWEEN 1 AND 3
      GROUP BY created_by_id, EXTRACT('year' FROM date_start)
      HAVING SUM((attrs_json->>'1718')::float)>91*1.604
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Quarterly Track 2',
      'At least 91 miles walked April to June in any year',
      COUNT(*),
      'Number of times this user has achieved 91 miles in April to June for any year'
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start)
      FROM track_data
      WHERE EXTRACT('month' FROM date_start) BETWEEN 4 AND 6
      GROUP BY created_by_id, EXTRACT('year' FROM date_start)
      HAVING SUM((attrs_json->>'1718')::float)>91*1.604
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Quarterly Track 3',
      'At least 91 miles walked July to September in any year',
      COUNT(*),
      'Number of times this user has achieved 91 miles in July to September for any year'
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start)
      FROM track_data
      WHERE EXTRACT('month' FROM date_start) BETWEEN 7 AND 9
      GROUP BY created_by_id, EXTRACT('year' FROM date_start)
      HAVING SUM((attrs_json->>'1718')::float)>91*1.604
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Quarterly Track 4',
      'At least 91 miles walked October to December in any year',
      COUNT(*),
      'Number of times this user has achieved 91 miles in October to December for any year'
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start)
      FROM track_data
      WHERE EXTRACT('month' FROM date_start) BETWEEN 10 AND 12
      GROUP BY created_by_id, EXTRACT('year' FROM date_start)
      HAVING SUM((attrs_json->>'1718')::float)>91*1.604
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Ultimate Tracker (' || year::text || ')',
      'All Quarterly Track badges completed in a given year',
      year::float,
      'Year the Ultimate Tracker was completed for'
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start) as year, FLOOR(EXTRACT('month' FROM date_start)/3)
      FROM track_data
      GROUP BY created_by_id, EXTRACT('year' FROM date_start), FLOOR(EXTRACT('month' FROM date_start)/3)
      HAVING SUM((attrs_json->>'1718')::float)>91*1.604
    ) AS subtable
    GROUP BY created_by_id, year
    HAVING count(*)>=4
    UNION ALL
    /********/
    SELECT created_by_id,
      'Beachmaster',
      'At least 10 records on a single beach',
      MAX(value),
      'Maximum number of records on a single beach'
    FROM (
      SELECT s.created_by_id,
        COUNT(o.id) as value
      FROM track_data s
      JOIN cache_occurrences_functional o ON o.parent_sample_id=s.id
      GROUP BY s.created_by_id, s.location_name
      HAVING count(o.id)>10
      ORDER BY s.created_by_id, count(o.id) DESC
    ) as subquery
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT s.created_by_id,
      'Clean Consience',
      'Recorded at least 15 clean beach conditions',
      COUNT(s.*),
      'Number of times a clean beach has been recorded'
    FROM track_data s
    WHERE s.attrs_json->>'1722' IN ('Spotless', 'Mostly Clean')
    GROUP BY s.created_by_id
    HAVING COUNT(s.*) >= 15
    UNION ALL
    /********/
    SELECT * FROM (
      WITH weekly_activity AS (
        SELECT
          created_by_id,
          date_trunc('week', date_start)::date as week_start
        FROM track_data
        GROUP BY created_by_id, week_start
      ),
      ranked_weeks AS (
        SELECT
          created_by_id,
          week_start,
        ROW_NUMBER() OVER (PARTITION BY created_by_id ORDER BY week_start) AS rn
        FROM weekly_activity
      ),
      grouped_weeks AS (
        SELECT
          created_by_id,
        week_start,
        rn,
        week_start - (rn || ' weeks')::interval as grp
        FROM ranked_weeks
      ),
      consecutive_weeks AS (
        SELECT
          created_by_id,
        COUNT(*) as weeks_in_a_row
        FROM grouped_weeks
        GROUP BY created_by_id, grp
        HAVING COUNT(*) > 10
      )
      SELECT created_by_id,
        '10 week streak',
        'Recorded a track for 10 weeks in a row.',
        MAX(weeks_in_a_row),
        'Maximum weeks in a row this user has recorded a track'
      FROM consecutive_weeks
      GROUP BY created_by_id
    ) AS streak_data
    UNION ALL
    /********/
    SELECT DISTINCT created_by_id,
      CASE mmdd
        WHEN 227 THEN 'Polar Bear Day'
      WHEN 322 THEN 'International Seal Day'
      WHEN 414 THEN 'Dolphin Day'
      END,
      CASE mmdd
        WHEN 227 THEN 'Walk a beach on 27th February in any year'
      WHEN 322 THEN 'Walk a beach on 22nd March in any year'
      WHEN 414 THEN 'Walk a beach on 14th April in any year'
      END,
      NULL::float,
      NULL::text
    FROM track_data
    WHERE mmdd IN (227, 322)
    UNION ALL
    /********/
    SELECT created_by_id,
      'Shag 60m',
      'Distance tracked on a single beach equivalent to the dive depth record of a Shag multiplied by 10',
      COUNT(*),
      'Number of beaches 600m has been tracked on for this user'
    FROM (
      SELECT created_by_id, location_name
      FROM track_data
      GROUP BY created_by_id, location_name
      HAVING SUM((attrs_json->>'1718')::float)>0.6
    ) AS subtable
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Harbour Seal 400m',
      'Distance tracked on a single beach equivalent to the dive depth record of a Harbour Seal multiplied by 10',
      COUNT(*),
      'Number of beaches 4km has been tracked on for this user'
    FROM (
      SELECT created_by_id, location_name
      FROM track_data
      GROUP BY created_by_id, location_name
      HAVING SUM((attrs_json->>'1718')::float)>4
    ) AS subtable
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Northern Bottlenose Whale 2340m',
      'Distance tracked on a single beach equivalent to the dive depth of a orthern Bottlenose Whale multiplied by 10',
      COUNT(*),
      'Number of beaches 23.4km has been tracked on for this user'
    FROM (
      SELECT created_by_id, location_name
      FROM track_data
      GROUP BY created_by_id, location_name
      HAVING SUM((attrs_json->>'1718')::float)>23.4
    ) AS subtable
    GROUP BY created_by_id;

    -- Update existing badges with new metrics.
    UPDATE custom_cache_tables.beachtrack_badges b
    SET metric = bld.metric,
      metric_meaning = bld.metric_meaning,
      description = bld.description,
      updated_on = CURRENT_TIMESTAMP
    FROM beachtrack_badges_build bld
    WHERE b.user_id = bld.user_id
      AND b.badge = bld.badge
      AND (b.metric <> bld.metric OR b.metric_meaning <> bld.metric_meaning OR b.description <> bld.description);

    -- Insert new badges that do not already exist, giving them an award date
    -- of today.
    INSERT INTO custom_cache_tables.beachtrack_badges
    SELECT bld.user_id, bld.badge, bld.description, bld.metric, bld.metric_meaning, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    FROM beachtrack_badges_build bld
    LEFT JOIN custom_cache_tables.beachtrack_badges b ON b.user_id = bld.user_id
    AND b.badge = bld.badge
    WHERE b.user_id IS NULL;
  QRY;
}