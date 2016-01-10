---
date: 2015-10-02 23:57:19
title: Chunked Uploads with Titanium
tags: Titanium, Ti.NetworkClient
excerpt: The Titanium.Network.HTTPClient Object fails for large file uploads under iOS since the whole request is build entirely in memory by the Titanium SDK. Chunked uploading to the rescue!
issue: 002

last_updated: 2016-01-10 17:50:00
---

The request and the response of Titanium's HTTPClient ([Ti.Network.HTTPClient][docHTTP]) is built entirely in memory. This means that your app will crash with a memory exception when you try up- or downloading large data. It depends on the smartphone's hardware, but the trouble starts at about 100 MB of request/response data.

## Status Quo
For large resonse data (i.e. downloads), there is the [*file*][docHTTPfile] property of the HTTPClient object. This writes the response directly to that file avoiding memory issues. For the request part (i.e. upload), no such property exists.

The HTTPClient implementation for Android does detect file resources in the request data and streams directly from the file instead of loading it into memory.

## TiHTTPClient vs. APSHTTPClient
Titanium did handle (and still does under Android) file uploads from a file handle well, but with the release of Titanium SDK 3.3.0, the TiHTTPClient was replaced by the APSHTTPClient lib [[TIMOB-16883]][timob]. From there on, file uploads from a file handle were not streamed from the filesystem but converted to a blob. When the request was finally build in memory large blobs (files) cause the app to fail with memory error.

Before TiSDK3.3.0, the following code did manage large file uploads well. From TiDSK3.3.0 the file handle was converted to a blob making the build of the request fail for large files.

	#!Javascript@0
	var file = Ti.Filesystem.getFile('large_file.mp4');
	var xhr = Ti.Network.HTTPCLient();
	xhr.open(..);
	xhr.send({
		data: file
	})

The APSHTTPClient library uses the common NSURLConnection and could be adopted to stream files from the filesystem instead of putting them in memory. I really tried to do it but things get quite complicated real quick when you have to craft the multipart HTTP POST request by yourself. And since the APSHTTPClient is an external lib, it's even harder to hack something into it. There has to be a simpler way..

## What about chunking?
The idea would be to split up large uploads in chunks, send them one by one to the server. Since a single chunk is small, there will be no memory issues. Another bonus is that if an upload request fails, you do not have to start all over again, but can retry from the current chunk.

Titanium has everything on board that is needed for chunking, mainly [Ti.Buffer][docBuffer] and [Ti.Stream][docStream]. Yay.

Let's assume we want to record a video from the camera with *Ti.Media.ShowCamera()* and upload that video to a server. Sounds easy enough - but it will fail for long videos **under iOS** because of the too large request body of the HTTPClient. For example, on an iPhone6, the app will crash when the recorded video is longer than 2 minutes.

## Simple XHR upload example with file chunks

This is a common JS example module (save as xhr.js):

	#!Javascript@0
	exports.chunkedXHR = function(url, file, callback) {
		var chunk_size = 1048576 * 50; // 50M
		var that = this;
		var xhr = Ti.Network.createHTTPClient();

	    var size = file.size;
		var chunks = Math.ceil(size/chunk_size);
		var stream = file.open(Ti.Filesystem.MODE_READ);
		var buffer = Ti.createBuffer({length: chunk_size});
		var chunk = 1;
		var bytes = 0;

		var onload = function(e) {
			if (chunks == chunk) {
				buffer.release();
				callback(true);
			} else {
				chunk++;
				go();
			}
		}

		xhr.onerror = function() {
			callback(false);
		};
		xhr.onload = onload;

		var go = function() {
			if (chunk == chunks) {
				// last chunk
				var length = size - chunk_size*(chunk-1);
				buffer.setLength(length);
			}
			bytes = stream.read(buffer);
			if (!bytes) return;
			var data = {};
			data.data = buffer.toBlob();
			data.chunk = chunk;
			data.chunks = chunks;
			data.filename = file.name;

			xhr.open('POST', url);
			xhr.send(data||{});
		}
		go();

		return xhr;
	}

Usage:

	#!Javascript@0
	var xhr = require('xhr');
	var file = Ti.Filesystem.getFile('large_file.mp4');
	xhr.chunkedXHR('http://localhost/chunk.php', file, function(success) {
		Ti.API.info('XHR callback: ' + success);
	});


## Server-Side
The server will just concatenate the chunks. PHP example:

	#!PHP@1
	// temporary file name
	$tmp = $_FILES['data']['tmp_name'];
	// real/final file name
	$filename = $_POST['filename'];
	// current chunk number (starts at 1)
	$chunk = isset($_POST['chunk']) ? $_POST['chunk'] : 0;

	if ($chunk > 1) {
		// this handles chunk nr 2..n
		// number of chunks total
		$chunks = $_POST['chunks'];

		// just concatenate the chunk
		exec("cat $tmp >> $filename");

		if ($chunks == $chunk) {
			// $filename is now complete. do something!
		}

	} else {
		// no chunking or first chunk. just move it
		move_uploaded_file($tmp, $filename);
	}

Please note that these examples are very basic and not meant for use in production. Chunks that failed to upload due to a connection problem should be retried. This makes chunked uploads also valuable for Android because the upload does not start from the very beginning every time a timeout occurs.

[docHTTP]: http://docs.appcelerator.com/platform/latest/#!/api/Titanium.Network.HTTPClient
[docHTTPfile]: http://docs.appcelerator.com/platform/latest/#!/api/Titanium.Network.HTTPClient-property-file
[timob]: https://jira.appcelerator.org/browse/TIMOB-16883
[docBuffer]: http://docs.appcelerator.com/platform/latest/#!/api/Titanium.Buffer
[docStream]: http://docs.appcelerator.com/platform/latest/#!/api/Titanium.Stream