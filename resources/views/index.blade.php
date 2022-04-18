<!DOCTYPE html>
<html>
	<head>
		<title>test</title>
	</head>

	<body>
		<table>
			<thead>
				<tr>
					<th>Operation &numero;</th>
					<th>Commission</th>
				</tr>
			</thead>
			<tbody>
				@foreach($results as $key => $result)
					<tr>
						<td>{{$key + 1}}</td>
						<td>{{$result}}</td>
					</tr>
				@endforeach
			</tbody>
		</table>
	</body>
</html>