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
    CREATE TABLE IF NOT EXISTS custom_cache_tables.beachtrack_badges(
      user_id integer NOT NULL,
      badge text NOT NULL,
      description text NOT NULL,
      metric float,
      metric_meaning text,
      awarded boolean DEFAULT false,
      awarded_on timestamp DEFAULT CURRENT_TIMESTAMP,
      updated_on timestamp with time zone NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE INDEX IF NOT EXISTS ix_beachtrack_badges_user_id_date ON custom_cache_tables.beachtrack_badges(user_id, updated_on);

    -- Delete ephemeral badges from previous years
    DELETE FROM custom_cache_tables.beachtrack_badges
    WHERE badge LIKE 'Quarterly Track%'
    AND badge NOT LIKE '%(' || EXTRACT('year' FROM now())::text || ')';

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
      COUNT(DISTINCT CASE WHEN SUBSTRING(attrs_json->>'1715' FROM 12 FOR 5) <= '08:00' THEN id ELSE NULL END) as metric,
      'Total number of walks started before 08:00' as metric_meaning,
    COUNT(DISTINCT CASE WHEN SUBSTRING(attrs_json->>'1715' FROM 12 FOR 5) <= '08:00' THEN id ELSE NULL END) >= 5 as awarded
    INTO TEMPORARY beachtrack_badges_build
    FROM track_data
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Sperm Whale migration',
      'Walked as far as a Sperm Whale migrates',
      SUM((attrs_json->>'1718')::float),
      'Total distance walked in km',
    SUM((attrs_json->>'1718')::float)>4000 as awarded
    FROM track_Data
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Porpoise' as badge,
      'Walked the same beach at least 5 times',
      MAX(value),
      'Maximum number of walks on the same beach',
    MAX(awarded::integer)=1 as awarded
    FROM (
      SELECT created_by_id,
        COUNT(DISTINCT id) AS value,
    COUNT(DISTINCT id)>=5 as awarded
      FROM track_data
      GROUP BY created_by_id, location_name
      ORDER BY created_by_id, count(id) DESC
    ) AS subquery
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Pilot Whale',
      'Walked the same beach at least 25 times',
      MAX(value),
      'Maximum number of walks on the same beach',
    MAX(awarded::integer)=1 as awarded
    FROM (
      SELECT created_by_id,
        COUNT(DISTINCT id) AS value,
    COUNT(DISTINCT id)>=25 as awarded
      FROM track_data
      GROUP BY created_by_id, location_name
      ORDER BY created_by_id, count(id) DESC
    ) AS subquery
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Minke Whale',
      'Walked the same beach at least 50 times',
      MAX(value),
      'Maximum number of walks on the same beach',
    MAX(awarded::integer)=1 as awarded
    FROM (
      SELECT created_by_id,
        COUNT(id) as value,
    COUNT(DISTINCT id)>=50 as awarded
      FROM track_data
      GROUP BY created_by_id, location_name
      ORDER BY created_by_id, count(id) DESC
    ) AS subquery
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Going the distance',
      'A single track of at least 5km',
      MAX((attrs_json->>'1718')::float),
      'Maximum distance of a single track for this user',
    MAX((attrs_json->>'1718')::float)>=5 as awarded
    FROM track_data
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Time to Track',
      'At least one month with at least 10 hours tracked',
      EXTRACT('hours' FROM MAX(hours_in_month)),
      'Maximum number of hours tracked in a month',
    MAX(hours_in_month) >= '10:00:00'::interval as awarded
    FROM (
      SELECT created_by_id, date_trunc('month', date_start), SUM((attrs_json->>'1717')::interval) as hours_in_month
      FROM track_data
    GROUP BY created_by_id, date_trunc('month', date_start)
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Quarterly Track 1 (' || EXTRACT('year' FROM now())::text || ')',
      'At least 91 miles walked January to March in the current year',
      MAX(miles_walked),
      'Maximum number of miles January to March for the current year',
    MAX(miles_walked) > 91 as awarded
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start), SUM(
      CASE WHEN EXTRACT('month' FROM date_start) BETWEEN 1 AND 3 THEN (attrs_json->>'1718')::float ELSE 0 END
    )*1.604 as miles_walked
      FROM track_data
    WHERE EXTRACT('year' FROM date_start) = EXTRACT('year' FROM now())
      GROUP BY created_by_id, EXTRACT('year' FROM date_start)
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Quarterly Track 2 (' || EXTRACT('year' FROM now())::text || ')',
      'At least 91 miles walked April to June in the current year',
      MAX(miles_walked),
      'Maximum number of miles April to June for the current year',
    MAX(miles_walked) > 91 as awarded
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start), SUM(
      CASE WHEN EXTRACT('month' FROM date_start) BETWEEN 4 AND 6 THEN (attrs_json->>'1718')::float ELSE 0 END
    )*1.604 as miles_walked
      FROM track_data
    WHERE EXTRACT('year' FROM date_start) = EXTRACT('year' FROM now())
      GROUP BY created_by_id, EXTRACT('year' FROM date_start)
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Quarterly Track 3 (' || EXTRACT('year' FROM now())::text || ')',
      'At least 91 miles walked July to September in the current year',
      MAX(miles_walked),
      'Maximum number of miles July to September for the current year',
    MAX(miles_walked) > 91 as awarded
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start), SUM(
      CASE WHEN EXTRACT('month' FROM date_start) BETWEEN 7 AND 9 THEN (attrs_json->>'1718')::float ELSE 0 END
    )*1.604 as miles_walked
      FROM track_data
    WHERE EXTRACT('year' FROM date_start) = EXTRACT('year' FROM now())
      GROUP BY created_by_id, EXTRACT('year' FROM date_start)
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Quarterly Track 4 (' || EXTRACT('year' FROM now())::text || ')',
      'At least 91 miles walked October to December in the current year',
      MAX(miles_walked),
      'Maximum number of miles walked October to December for the current year',
    MAX(miles_walked) > 91 as awarded
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start), SUM(
      CASE WHEN EXTRACT('month' FROM date_start) BETWEEN 10 AND 12 THEN (attrs_json->>'1718')::float ELSE 0 END
    )*1.604 as miles_walked
      FROM track_data
    WHERE EXTRACT('year' FROM date_start) = EXTRACT('year' FROM now())
      GROUP BY created_by_id, EXTRACT('year' FROM date_start)
    ) AS subtable
    GROUP BY 1, 2, 3
    UNION ALL
    /********/
    SELECT created_by_id,
      'Ultimate Tracker (' || year::text || ')',
      'All Quarterly Track badges completed in a given year',
      SUM(CASE WHEN miles_walked > 91 THEN 1 ELSE 0 END),
      'Number of Quarterly Track badges completed in the year',
    SUM(CASE WHEN miles_walked > 91 THEN 1 ELSE 0 END) >= 4 AS awarded
    FROM (
      SELECT created_by_id, EXTRACT('year' FROM date_start) as year, FLOOR(EXTRACT('month' FROM date_start)/3) as quarter, SUM((attrs_json->>'1718')::float)*1.604 as miles_walked
      FROM track_data
      GROUP BY created_by_id, EXTRACT('year' FROM date_start), FLOOR(EXTRACT('month' FROM date_start)/3)
    ) AS subtable
    GROUP BY created_by_id, year
    UNION ALL
    /********/
    SELECT created_by_id,
      'Beachmaster',
      'At least 10 records on a single beach',
      MAX(value),
      'Maximum number of records on a single beach',
    MAX(value)>=10 as awarded
    FROM (
      SELECT s.created_by_id,
        COUNT(o.id) as value
      FROM track_data s
      LEFT JOIN cache_occurrences_functional o ON o.parent_sample_id=s.id
      GROUP BY s.created_by_id, s.location_name
      ORDER BY s.created_by_id, count(o.id) DESC
    ) as subquery
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT s.created_by_id,
      'Clean Consience',
      'Recorded at least 15 clean beach conditions',
      SUM(CASE WHEN s.attrs_json->>'1722' IN ('Spotless', 'Mostly Clean') THEN 1 ELSE 0 END),
      'Number of times a clean beach has been recorded',
    SUM(CASE WHEN s.attrs_json->>'1722' IN ('Spotless', 'Mostly Clean') THEN 1 ELSE 0 END) >= 15 as awarded
    FROM track_data s
    GROUP BY s.created_by_id
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
      )
      SELECT created_by_id,
        '10 week streak',
        'Recorded a track for 10 weeks in a row.',
        MAX(weeks_in_a_row),
        'Maximum weeks in a row this user has recorded a track',
    MAX(weeks_in_a_row) >= 10 as awarded
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
      NULL::text,
    true as awarded
    FROM track_data
    WHERE mmdd IN (227, 322)
    UNION ALL
    /********/
    SELECT created_by_id,
      'Shag 60m',
      'Distance tracked on a single beach equivalent to the dive depth record of a Shag multiplied by 10',
      MAX(kms_walked),
      'Max kms walked on a single beach',
    MAX(kms_walked) >= 0.6 as awarded
    FROM (
      SELECT created_by_id, location_name, SUM((attrs_json->>'1718')::float) as kms_walked
      FROM track_data
      GROUP BY created_by_id, location_name
    ) AS subtable
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Harbour Seal 400m',
      'Distance tracked on a single beach equivalent to the dive depth record of a Harbour Seal multiplied by 10',
      MAX(kms_walked),
      'Max kms walked on a single beach',
    MAX(kms_walked) >= 4 as awarded
    FROM (
      SELECT created_by_id, location_name, SUM((attrs_json->>'1718')::float) as kms_walked
      FROM track_data
      GROUP BY created_by_id, location_name
    ) AS subtable
    GROUP BY created_by_id
    UNION ALL
    /********/
    SELECT created_by_id,
      'Northern Bottlenose Whale 2340m',
      'Distance tracked on a single beach equivalent to the dive depth of a Northern Bottlenose Whale multiplied by 10',
      MAX(kms_walked),
      'Max kms walked on a single beach',
    MAX(kms_walked) >= 23.4 as awarded
    FROM (
      SELECT created_by_id, location_name, SUM((attrs_json->>'1718')::float) as kms_walked
      FROM track_data
      GROUP BY created_by_id, location_name
    ) AS subtable
    GROUP BY created_by_id;

    -- Update existing badges with new metrics.
    UPDATE custom_cache_tables.beachtrack_badges b
    SET metric = bld.metric,
      metric_meaning = bld.metric_meaning,
      description = bld.description,
    awarded = b.awarded OR bld.awarded,
    awarded_on = CASE WHEN b.awarded_on IS NULL AND (b.awarded OR bld.awarded) THEN CURRENT_TIMESTAMP ELSE b.awarded_on END,
    updated_on = CURRENT_TIMESTAMP
    FROM beachtrack_badges_build bld
    WHERE b.user_id = bld.user_id
      AND b.badge = bld.badge
      AND (b.metric <> bld.metric OR b.metric_meaning <> bld.metric_meaning OR b.description <> bld.description);

    -- Insert new badges that do not already exist, giving them an award date
    -- of today.
    INSERT INTO custom_cache_tables.beachtrack_badges
    SELECT bld.user_id, bld.badge, bld.description, bld.metric, bld.metric_meaning, bld.awarded,
      CASE WHEN bld.awarded THEN CURRENT_TIMESTAMP ELSE NULL END as awarded_on,
      CURRENT_TIMESTAMP
    FROM beachtrack_badges_build bld
    LEFT JOIN custom_cache_tables.beachtrack_badges b ON b.user_id = bld.user_id
    AND b.badge = bld.badge
    WHERE b.user_id IS NULL;
    QRY;
}