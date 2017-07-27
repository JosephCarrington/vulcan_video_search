var allRecords = [];
jQuery(document).ready(function($) {
  // Do some browser tests
  if (!window.File || !window.FileReader || !window.FileList || !window.Blob) {
    logToScreen("Cannot read files. Please try a modern browser");
    return;
  }

  function readAndParseFile(file) {
    return new Promise(function(resolve, reject) {
      var fr = new FileReader();
      fr.onerror = function(err) {
        reject(err);
      };

      fr.onload = function(event) {
        logToScreen('finished reading file');
        var thisFilesRecords = [];
        // Begin reading file line-by-line
        var lines = event.target.result.split(/[\r\n]+/g);
        for(var x = 0, y = lines.length; x < y; x++) {
          var line = lines[x];
          // Remove copious whitespace chars
          line = line.trim();

          // Only pay attention to lines that end with 33 or 1, which are store codes
          var re = /\s(33|1)$/;
          if(re.test(line)) {
            // Line has 33 or 1 at the end, create a video object from the line
            // See below for example line

            var titleChars = 41,
              categoryChars = 9,
              locationChars = 39,
              storeChars = 3;

            if(line.length != titleChars + categoryChars + locationChars + storeChars + 1) {
              continue;
            }

            var video = parseLine(line, titleChars, categoryChars, locationChars, storeChars);

            // And push the object to the array
            thisFilesRecords.push(video);
          }
        }
        var percentageGood = (thisFilesRecords.length / lines.length) * 100;
        logToScreen('Finished parsing file, found ' + thisFilesRecords.length + ' usable records out of ' + lines.length + ' (' + percentageGood.toFixed() + '%)');
        resolve(thisFilesRecords);
      };
      fr.readAsText(file);
    });
  }

  function readAndParseAllFiles(files) {
    return new Promise(function(resolve, reject) {
      var sequence = Promise.resolve();
      Array.from(files).forEach(function(file) {
        sequence = sequence.then(function() {
          return readAndParseFile(file);
        }).then(function(entries) {
          allRecords = allRecords.concat(entries);
        });
      });
      resolve(sequence);
    });

  }

  $('#infiles').on('change', function(event) {
    $('#parsingInfo').text('');

    readAndParseAllFiles(event.target.files)
    .then(function() {
      return new Promise(function(resolve, reject){
        logToScreen('Total records to upload: ' + allRecords.length);
        uploadRecords(allRecords).then(function(message) {
          resolve(message);
        });
      });
    })
    .then(function(message) {
      return new Promise(function(resolve, reject) {
        var feedback = 'Finished upload. Created file ';
        feedback += '<a href="' + serverVariables.pluginDirURL + message +'">';
        feedback += message;
        feedback += '</a>';
        logToScreen(feedback);
        resolve(message);
      });
    })
    .then(function(message) {
      return new Promise(function(resolve, reject) {
        logToScreen("Clearing video database and importing generated file");
        var req = new XMLHttpRequest();
        req.open('POST', ajaxurl + '?action=vulcan_video_clear_and_insert', true);
        req.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        req.onreadystatechange = function() {
          if(req.readyState == XMLHttpRequest.DONE && req.status == 200) {
            resolve(req.response);
          }
        };

        req.send('filename=' + message);
      });
    })
    .then(function(message) {
      logToScreen(message);
    });
  });

  function uploadRecords(records) {
    return new Promise(function(resolve, reject) {
      var req = new XMLHttpRequest();
      req.open('POST', ajaxurl + '?action=vulcan_video_upload', true);
      req.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

      req.onreadystatechange = function() {
        if(req.readyState == XMLHttpRequest.DONE && req.status == 200) {
          resolve(req.response);
        }
      };

      logToScreen('Starting upload...');
      req.send(JSON.stringify(allRecords));
    });
  }

  function logToScreen(message) {
    $('#parsingInfo').append(message + '<br />');
  }
});

function parseLine(line, titleChars, categoryChars, locationChars, storeChars) {
  // Example line below:
  // ALL THAT JAZZ.DVD                        MU       MUSICALS                                  1

  // Create a blank videoRecord item
  var video = new VideoRecord();

  // THe title string sometimes contains the format
  var titleString = line.substr(0, titleChars).trim();
  var DVDRe = /\.DVD$/;
  var BLURe = /\.BLU$/;

  if(DVDRe.test(titleString)) {
    video.format = 'DVD';
    titleString = titleString.replace('.DVD', '');
  }
  else if(BLURe.test(titleString)) {
    video.format = 'BLU';
    titleString = titleString.replace('.BLU', '');
  }
  else {
    video.format = "VHS";
  }

  video.title = titleString.toString();
  video.category = line.substr(titleChars, categoryChars).trim();
  video.location = line.substr(titleChars + categoryChars, locationChars).trim();
  video.location = replaceSpecialChars(video.location);
  video.store = parseInt(line.substr(titleChars + categoryChars + locationChars + 1, storeChars).trim());
  return video;
}

function replaceSpecialChars(string) {
  string = string.replace(/\,/g, '\\,')
  .replace(/\"/g, '\\"');

  return string;
}

function VideoRecord() {
  this.id = null;
  this.title = null;
  this.format = null;
  this.category = null;
  this.location = null;
  this.store = null;
}
