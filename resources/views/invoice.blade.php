<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">

    <style>
        body{
            font-family: DejaVu Sans, sans-serif;
            padding: 30px;
        }

        h1{
            text-align:center;
        }

        table{
            width:100%;
            border-collapse: collapse;
            margin-top:20px;
        }

        th, td{
            border:1px solid #000;
            padding:10px;
            text-align:center;
        }

        th{
            background:#eee;
        }

        .total{
            margin-top:20px;
            text-align:right;
            font-size:20px;
            font-weight:bold;
        }
    </style>
</head>

<body>

<h1>Invoice</h1>

<p>
    <strong>Invoice Number:</strong>
    {{ $order->num }}
</p>

<p>
    <strong>Customer:</strong>
    {{ $user->name }}
</p>

<p>
    <strong>Email:</strong>
    {{ $user->email }}
</p>

<table>

    <thead>
    <tr>
        <th>Product</th>
        <th>Quantity</th>
        <th>Unit Price</th>
        <th>Total</th>
    </tr>
    </thead>

    <tbody>

    @foreach($order->items as $item)

        <tr>
            <td>{{ $item->product->name }}</td>

            <td>{{ $item->quantity }}</td>

            <td>
                {{ number_format($item->unit_price,2) }}
            </td>

            <td>
                {{ number_format($item->total_price,2) }}
            </td>
        </tr>

    @endforeach

    </tbody>

</table>

<div class="total">
    Final Total:
    {{ number_format($order->total_price,2) }}
</div>

</body>
</html>
