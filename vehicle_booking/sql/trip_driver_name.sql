-- Adds the driver captured on the Vehicle Request Form: the person who will
-- actually drive the vehicle on the trip. (Not the same as driver_id, which
-- is the user with the driver role who assigned the car.)
--
--   trip_driver_id    the driver picked from the users list (NULL when
--                     "Other" was chosen and a name typed in)
--   trip_driver_name  the driver's name as it was at request time
--
-- Safe to run again.
--
--   mysql -u root vehicle_requisition < sql/trip_driver_name.sql

ALTER TABLE vehicle_requests
    ADD COLUMN IF NOT EXISTS trip_driver_name VARCHAR(255) NULL AFTER passengers;

ALTER TABLE vehicle_requests
    ADD COLUMN IF NOT EXISTS trip_driver_id INT NULL AFTER passengers;
