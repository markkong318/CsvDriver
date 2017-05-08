# CsvDriver
Csv Driver is a php library to read .csv file in flexible format with header.

if your csv has header like that
```
id,key,value
# data 1
1,aaa,b
```
then use this library, you could read the specific block of csv data with comment and mapping to array with header column name. In the above example, you will get the array like the following
```
[0] =>
  [id] => "# data 1"
  [key] => ""
  [value] => ""
[1]
  [id] => "1"
  [key] => "aaa"
  [value] => "b"
```
Also, you could modify the content of the array and update the whole csv file. the modified array will be inserted to csv file by id and place comment right before the data.

## Feature
1. Support read csv continues block with comment
2. Support read, insert, update data
3. Filter csv by callback function, you don`t have to memorize another ORM code
4. Low memory usage and only O(n) compare

## Usage
Do not forget to inclue the library first

1. Initailize object
```
$csv_driver = new CsvDriver("foo.csv")
```

2. Read data by condition
```
$data_array_list = $csv_driver->get_data_array_list_by_func(
  function($data_array){

    /* if you want the column data, return true value */
    return true;
  }
);
```
3. Save modified data
```
// set to buffer
$csv_driver->write_data_array_list_to_line_buf($data_array_list);

// update csv
$csv_driver->update_csv();
```
## Limitation
1. If the callback function return false in reading, the reading will be interrupt. It means you only could select a continuous block of data

## Other suggestion
In my project, I extend the library to a model file for each csv file, so I could control every read/update process by the file and without to write some dirty codes for common function. It may be work in yours.

## License
Released under the [MIT license](http://www.opensource.org/licenses/MIT).
