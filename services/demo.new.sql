SELECT
    cast(:label AS text) AS "Етикет",
    current_number AS "Tom",
    to_char(current_number, 'FMRN') AS "Jerry"
 FROM generate_series (1, 1000, 1) AS t (current_number)
 WHERE current_number BETWEEN :lower_limit AND :upper_limit
 LIMIT 100;