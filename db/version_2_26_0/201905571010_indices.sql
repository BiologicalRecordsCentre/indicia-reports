-- #slow script#
-- Optimise the most common searches on UKSI on iRecord.
CREATE INDEX ix_cache_taxon_searchterms_fulltext_filtered
  ON cache_taxon_searchterms
  USING gin
  (to_tsvector('simple'::regconfig, quote_literal(quote_literal(original::text))))
  WHERE simplified = false AND (language_iso<>'lat' or preferred=true) AND name_type<>'A' AND taxon_list_id=15