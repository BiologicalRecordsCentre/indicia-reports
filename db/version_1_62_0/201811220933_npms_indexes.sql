-- #postgres user#
-- Partial index on NPMS data to optimise reports.
-- Replace <sample_attribute_id> with the ID of the survey sample attribute for NPMS,
-- which is 227 on the live warehouse.
CREATE INDEX ix_npms_sav_survey_id ON sample_attribute_values(int_value) WHERE sample_attribute_id=<sample_attribute_id>;