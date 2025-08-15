# Trip Processing Script

This script processes GPS points from a CSV file, splits them into trips, and
outputs a GeoJSON file.

## Usage

```bash
php your_script.php points.csv
```

points.csv – Path to the input CSV file containing GPS points.

## Output Files

`rejects.log` – Contains rows with invalid coordinates or timestamps.

`test-geojson` – Generated GeoJSON file with processed trip data.
