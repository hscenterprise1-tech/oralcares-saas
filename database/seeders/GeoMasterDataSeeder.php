<?php

namespace Database\Seeders;

use App\Models\GeoArea;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoDistrict;
use App\Models\GeoPincode;
use App\Models\GeoState;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GeoMasterDataSeeder extends Seeder
{
    /**
     * Run the geo master data seeds.
     */
    public function run(): void
    {
        // 1. Primary Country: India
        $india = GeoCountry::updateOrCreate(
            ['code' => 'IN'],
            [
                'name' => 'India',
                'phone_code' => '+91',
                'currency_code' => 'INR',
                'is_active' => true,
            ]
        );

        // 2. All 36 States and Union Territories of India
        $statesData = [
            // 28 States
            ['code' => 'AP', 'name' => 'Andhra Pradesh', 'gst_code' => '37', 'type' => 'state'],
            ['code' => 'AR', 'name' => 'Arunachal Pradesh', 'gst_code' => '12', 'type' => 'state'],
            ['code' => 'AS', 'name' => 'Assam', 'gst_code' => '18', 'type' => 'state'],
            ['code' => 'BR', 'name' => 'Bihar', 'gst_code' => '10', 'type' => 'state'],
            ['code' => 'CG', 'name' => 'Chhattisgarh', 'gst_code' => '22', 'type' => 'state'],
            ['code' => 'GA', 'name' => 'Goa', 'gst_code' => '30', 'type' => 'state'],
            ['code' => 'GJ', 'name' => 'Gujarat', 'gst_code' => '24', 'type' => 'state'],
            ['code' => 'HR', 'name' => 'Haryana', 'gst_code' => '06', 'type' => 'state'],
            ['code' => 'HP', 'name' => 'Himachal Pradesh', 'gst_code' => '02', 'type' => 'state'],
            ['code' => 'JH', 'name' => 'Jharkhand', 'gst_code' => '20', 'type' => 'state'],
            ['code' => 'KA', 'name' => 'Karnataka', 'gst_code' => '29', 'type' => 'state'],
            ['code' => 'KL', 'name' => 'Kerala', 'gst_code' => '32', 'type' => 'state'],
            ['code' => 'MP', 'name' => 'Madhya Pradesh', 'gst_code' => '23', 'type' => 'state'],
            ['code' => 'MH', 'name' => 'Maharashtra', 'gst_code' => '27', 'type' => 'state'],
            ['code' => 'MN', 'name' => 'Manipur', 'gst_code' => '14', 'type' => 'state'],
            ['code' => 'ML', 'name' => 'Meghalaya', 'gst_code' => '17', 'type' => 'state'],
            ['code' => 'MZ', 'name' => 'Mizoram', 'gst_code' => '15', 'type' => 'state'],
            ['code' => 'NL', 'name' => 'Nagaland', 'gst_code' => '13', 'type' => 'state'],
            ['code' => 'OD', 'name' => 'Odisha', 'gst_code' => '21', 'type' => 'state'],
            ['code' => 'PB', 'name' => 'Punjab', 'gst_code' => '03', 'type' => 'state'],
            ['code' => 'RJ', 'name' => 'Rajasthan', 'gst_code' => '08', 'type' => 'state'],
            ['code' => 'SK', 'name' => 'Sikkim', 'gst_code' => '11', 'type' => 'state'],
            ['code' => 'TN', 'name' => 'Tamil Nadu', 'gst_code' => '33', 'type' => 'state'],
            ['code' => 'TS', 'name' => 'Telangana', 'gst_code' => '36', 'type' => 'state'],
            ['code' => 'TR', 'name' => 'Tripura', 'gst_code' => '16', 'type' => 'state'],
            ['code' => 'UP', 'name' => 'Uttar Pradesh', 'gst_code' => '09', 'type' => 'state'],
            ['code' => 'UK', 'name' => 'Uttarakhand', 'gst_code' => '05', 'type' => 'state'],
            ['code' => 'WB', 'name' => 'West Bengal', 'gst_code' => '19', 'type' => 'state'],
            // 8 Union Territories
            ['code' => 'AN', 'name' => 'Andaman and Nicobar Islands', 'gst_code' => '35', 'type' => 'union_territory'],
            ['code' => 'CH', 'name' => 'Chandigarh', 'gst_code' => '04', 'type' => 'union_territory'],
            ['code' => 'DH', 'name' => 'Dadra and Nagar Haveli and Daman and Diu', 'gst_code' => '26', 'type' => 'union_territory'],
            ['code' => 'DL', 'name' => 'Delhi (NCT)', 'gst_code' => '07', 'type' => 'union_territory'],
            ['code' => 'JK', 'name' => 'Jammu and Kashmir', 'gst_code' => '01', 'type' => 'union_territory'],
            ['code' => 'LA', 'name' => 'Ladakh', 'gst_code' => '38', 'type' => 'union_territory'],
            ['code' => 'LD', 'name' => 'Lakshadweep', 'gst_code' => '31', 'type' => 'union_territory'],
            ['code' => 'PY', 'name' => 'Puducherry', 'gst_code' => '34', 'type' => 'union_territory'],
        ];

        $stateMap = [];
        foreach ($statesData as $s) {
            $stateRecord = GeoState::updateOrCreate(
                ['country_id' => $india->id, 'code' => $s['code']],
                [
                    'name' => $s['name'],
                    'gst_code' => $s['gst_code'],
                    'type' => $s['type'],
                    'is_active' => true,
                ]
            );
            $stateMap[$s['code']] = $stateRecord;
        }

        // 3. Hierarchical Master Data: Districts, Cities, Areas, and Pincodes
        $hierarchy = [
            // ── GUJARAT (GJ) ──
            [
                'state_code' => 'GJ',
                'district' => 'Ahmedabad',
                'city' => 'Ahmedabad',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'Navrangpura', 'pincode' => '380009', 'lat' => 23.0365, 'lng' => 72.5611],
                    ['name' => 'Satellite', 'pincode' => '380015', 'lat' => 23.0304, 'lng' => 72.5178],
                    ['name' => 'Vastrapur', 'pincode' => '380015', 'lat' => 23.0350, 'lng' => 72.5293],
                    ['name' => 'Bodakdev', 'pincode' => '380054', 'lat' => 23.0454, 'lng' => 72.5085],
                    ['name' => 'Prahlad Nagar', 'pincode' => '380015', 'lat' => 23.0076, 'lng' => 72.5085],
                    ['name' => 'CG Road', 'pincode' => '380006', 'lat' => 23.0298, 'lng' => 72.5562],
                    ['name' => 'Paldi', 'pincode' => '380007', 'lat' => 23.0121, 'lng' => 72.5627],
                    ['name' => 'Maninagar', 'pincode' => '380008', 'lat' => 22.9978, 'lng' => 72.6033],
                    ['name' => 'Raikhad', 'pincode' => '380001', 'lat' => 23.0225, 'lng' => 72.5714],
                    ['name' => 'SG Highway', 'pincode' => '380060', 'lat' => 23.0754, 'lng' => 72.5248],
                    ['name' => 'Thaltej', 'pincode' => '380059', 'lat' => 23.0519, 'lng' => 72.5028],
                    ['name' => 'Chandkheda', 'pincode' => '382424', 'lat' => 23.1118, 'lng' => 72.5939],
                ],
            ],
            [
                'state_code' => 'GJ',
                'district' => 'Surat',
                'city' => 'Surat',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Athwa Lines', 'pincode' => '395007', 'lat' => 21.1702, 'lng' => 72.7981],
                    ['name' => 'Adajan', 'pincode' => '395009', 'lat' => 21.1959, 'lng' => 72.7933],
                    ['name' => 'Vesu', 'pincode' => '395007', 'lat' => 21.1418, 'lng' => 72.7709],
                    ['name' => 'Ring Road', 'pincode' => '395002', 'lat' => 21.1860, 'lng' => 72.8360],
                    ['name' => 'Katargam', 'pincode' => '395004', 'lat' => 21.2266, 'lng' => 72.8312],
                ],
            ],
            [
                'state_code' => 'GJ',
                'district' => 'Vadodara',
                'city' => 'Vadodara',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Alkapuri', 'pincode' => '390007', 'lat' => 22.3107, 'lng' => 73.1704],
                    ['name' => 'Sayajigunj', 'pincode' => '390005', 'lat' => 22.3090, 'lng' => 73.1812],
                    ['name' => 'Manjalpur', 'pincode' => '390011', 'lat' => 22.2687, 'lng' => 73.1932],
                    ['name' => 'Gotri', 'pincode' => '390021', 'lat' => 22.3168, 'lng' => 73.1378],
                    ['name' => 'Karelibaug', 'pincode' => '390018', 'lat' => 22.3274, 'lng' => 73.2045],
                ],
            ],
            [
                'state_code' => 'GJ',
                'district' => 'Rajkot',
                'city' => 'Rajkot',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Kalawad Road', 'pincode' => '360005', 'lat' => 22.2837, 'lng' => 70.7634],
                    ['name' => 'Yagnik Road', 'pincode' => '360001', 'lat' => 22.2965, 'lng' => 70.7960],
                    ['name' => 'University Road', 'pincode' => '360005', 'lat' => 22.2912, 'lng' => 70.7710],
                ],
            ],
            [
                'state_code' => 'GJ',
                'district' => 'Gandhinagar',
                'city' => 'Gandhinagar',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Infocity', 'pincode' => '382007', 'lat' => 23.1906, 'lng' => 72.6289],
                    ['name' => 'Sector 21', 'pincode' => '382021', 'lat' => 23.2341, 'lng' => 72.6468],
                    ['name' => 'GIFT City', 'pincode' => '382355', 'lat' => 23.1601, 'lng' => 72.6841],
                ],
            ],
            [
                'state_code' => 'GJ',
                'district' => 'Bhavnagar',
                'city' => 'Bhavnagar',
                'tier' => 'tier_3',
                'areas' => [
                    ['name' => 'Waghawadi Road', 'pincode' => '364002', 'lat' => 21.7580, 'lng' => 72.1460],
                ],
            ],

            // ── MAHARASHTRA (MH) ──
            [
                'state_code' => 'MH',
                'district' => 'Mumbai City',
                'city' => 'Mumbai',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'Nariman Point', 'pincode' => '400021', 'lat' => 18.9269, 'lng' => 72.8229],
                    ['name' => 'Fort', 'pincode' => '400001', 'lat' => 18.9322, 'lng' => 72.8335],
                    ['name' => 'Colaba', 'pincode' => '400005', 'lat' => 18.9067, 'lng' => 72.8147],
                    ['name' => 'Lower Parel', 'pincode' => '400013', 'lat' => 18.9953, 'lng' => 72.8315],
                    ['name' => 'Dadar', 'pincode' => '400014', 'lat' => 19.0178, 'lng' => 72.8478],
                ],
            ],
            [
                'state_code' => 'MH',
                'district' => 'Mumbai Suburban',
                'city' => 'Mumbai Suburban',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'Bandra West', 'pincode' => '400050', 'lat' => 19.0596, 'lng' => 72.8295],
                    ['name' => 'BKC (Bandra Kurla Complex)', 'pincode' => '400051', 'lat' => 19.0664, 'lng' => 72.8687],
                    ['name' => 'Andheri West', 'pincode' => '400053', 'lat' => 19.1363, 'lng' => 72.8277],
                    ['name' => 'Andheri East', 'pincode' => '400069', 'lat' => 19.1197, 'lng' => 72.8464],
                    ['name' => 'Juhu', 'pincode' => '400049', 'lat' => 19.0988, 'lng' => 72.8264],
                    ['name' => 'Powai', 'pincode' => '400076', 'lat' => 19.1197, 'lng' => 72.9051],
                    ['name' => 'Borivali West', 'pincode' => '400092', 'lat' => 19.2307, 'lng' => 72.8567],
                ],
            ],
            [
                'state_code' => 'MH',
                'district' => 'Pune',
                'city' => 'Pune',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'Kothrud', 'pincode' => '411038', 'lat' => 18.5074, 'lng' => 73.8077],
                    ['name' => 'Koregaon Park', 'pincode' => '411001', 'lat' => 18.5362, 'lng' => 73.8939],
                    ['name' => 'Baner', 'pincode' => '411045', 'lat' => 18.5590, 'lng' => 73.7868],
                    ['name' => 'Viman Nagar', 'pincode' => '411014', 'lat' => 18.5679, 'lng' => 73.9143],
                    ['name' => 'Hinjawadi', 'pincode' => '411057', 'lat' => 18.5913, 'lng' => 73.7389],
                    ['name' => 'Shivajinagar', 'pincode' => '411005', 'lat' => 18.5314, 'lng' => 73.8446],
                ],
            ],
            [
                'state_code' => 'MH',
                'district' => 'Nagpur',
                'city' => 'Nagpur',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Dharampeth', 'pincode' => '440010', 'lat' => 21.1444, 'lng' => 79.0628],
                    ['name' => 'Ramdaspeth', 'pincode' => '440010', 'lat' => 21.1378, 'lng' => 79.0734],
                    ['name' => 'Sitabuldi', 'pincode' => '440012', 'lat' => 21.1466, 'lng' => 79.0833],
                ],
            ],
            [
                'state_code' => 'MH',
                'district' => 'Thane',
                'city' => 'Thane',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Naupada', 'pincode' => '400602', 'lat' => 19.1873, 'lng' => 72.9734],
                    ['name' => 'Ghodbunder Road', 'pincode' => '400615', 'lat' => 19.2678, 'lng' => 72.9644],
                ],
            ],
            [
                'state_code' => 'MH',
                'district' => 'Nashik',
                'city' => 'Nashik',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'College Road', 'pincode' => '422005', 'lat' => 20.0068, 'lng' => 73.7634],
                    ['name' => 'Mahatma Nagar', 'pincode' => '422007', 'lat' => 19.9984, 'lng' => 73.7541],
                ],
            ],

            // ── KARNATAKA (KA) ──
            [
                'state_code' => 'KA',
                'district' => 'Bengaluru Urban',
                'city' => 'Bengaluru',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'MG Road', 'pincode' => '560001', 'lat' => 12.9756, 'lng' => 77.6066],
                    ['name' => 'Indiranagar', 'pincode' => '560038', 'lat' => 12.9784, 'lng' => 77.6408],
                    ['name' => 'Koramangala', 'pincode' => '560034', 'lat' => 12.9352, 'lng' => 77.6245],
                    ['name' => 'HSR Layout', 'pincode' => '560102', 'lat' => 12.9121, 'lng' => 77.6446],
                    ['name' => 'Whitefield', 'pincode' => '560066', 'lat' => 12.9698, 'lng' => 77.7500],
                    ['name' => 'Jayanagar', 'pincode' => '560011', 'lat' => 12.9308, 'lng' => 77.5838],
                    ['name' => 'JP Nagar', 'pincode' => '560078', 'lat' => 12.9063, 'lng' => 77.5857],
                    ['name' => 'Electronic City', 'pincode' => '560100', 'lat' => 12.8399, 'lng' => 77.6770],
                    ['name' => 'Malleshwaram', 'pincode' => '560003', 'lat' => 13.0031, 'lng' => 77.5644],
                    ['name' => 'Hebbal', 'pincode' => '560024', 'lat' => 13.0358, 'lng' => 77.5970],
                ],
            ],
            [
                'state_code' => 'KA',
                'district' => 'Mysuru',
                'city' => 'Mysuru',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Jayalakshmipuram', 'pincode' => '570012', 'lat' => 12.3218, 'lng' => 76.6264],
                    ['name' => 'Gokulam', 'pincode' => '570002', 'lat' => 12.3354, 'lng' => 76.6341],
                ],
            ],
            [
                'state_code' => 'KA',
                'district' => 'Dakshina Kannada',
                'city' => 'Mangaluru',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Hampankatta', 'pincode' => '575001', 'lat' => 12.8682, 'lng' => 74.8427],
                    ['name' => 'Kadri', 'pincode' => '575002', 'lat' => 12.8876, 'lng' => 74.8624],
                ],
            ],

            // ── DELHI (DL) ──
            [
                'state_code' => 'DL',
                'district' => 'New Delhi',
                'city' => 'New Delhi',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'Connaught Place', 'pincode' => '110001', 'lat' => 28.6304, 'lng' => 77.2177],
                    ['name' => 'Barakhamba Road', 'pincode' => '110001', 'lat' => 28.6289, 'lng' => 77.2284],
                ],
            ],
            [
                'state_code' => 'DL',
                'district' => 'South Delhi',
                'city' => 'South Delhi',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'South Extension', 'pincode' => '110049', 'lat' => 28.5727, 'lng' => 77.2234],
                    ['name' => 'Hauz Khas', 'pincode' => '110016', 'lat' => 28.5494, 'lng' => 77.2001],
                    ['name' => 'Greater Kailash', 'pincode' => '110048', 'lat' => 28.5392, 'lng' => 77.2345],
                    ['name' => 'Saket', 'pincode' => '110017', 'lat' => 28.5244, 'lng' => 77.2104],
                    ['name' => 'Vasant Kunj', 'pincode' => '110070', 'lat' => 28.5293, 'lng' => 77.1528],
                ],
            ],
            [
                'state_code' => 'DL',
                'district' => 'Central Delhi',
                'city' => 'Central Delhi',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'Karol Bagh', 'pincode' => '110005', 'lat' => 28.6514, 'lng' => 77.1907],
                    ['name' => 'Rajendra Nagar', 'pincode' => '110060', 'lat' => 28.6414, 'lng' => 77.1812],
                ],
            ],

            // ── TAMIL NADU (TN) ──
            [
                'state_code' => 'TN',
                'district' => 'Chennai',
                'city' => 'Chennai',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'George Town', 'pincode' => '600001', 'lat' => 13.0903, 'lng' => 80.2854],
                    ['name' => 'T. Nagar', 'pincode' => '600017', 'lat' => 13.0418, 'lng' => 80.2341],
                    ['name' => 'Alwarpet', 'pincode' => '600018', 'lat' => 13.0334, 'lng' => 80.2528],
                    ['name' => 'Anna Nagar', 'pincode' => '600040', 'lat' => 13.0850, 'lng' => 80.2101],
                    ['name' => 'Adyar', 'pincode' => '600020', 'lat' => 13.0012, 'lng' => 80.2565],
                    ['name' => 'Velachery', 'pincode' => '600042', 'lat' => 12.9815, 'lng' => 80.2180],
                    ['name' => 'OMR / Thoraipakkam', 'pincode' => '600097', 'lat' => 12.9341, 'lng' => 80.2312],
                ],
            ],
            [
                'state_code' => 'TN',
                'district' => 'Coimbatore',
                'city' => 'Coimbatore',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'RS Puram', 'pincode' => '641002', 'lat' => 11.0089, 'lng' => 76.9490],
                    ['name' => 'Gandhipuram', 'pincode' => '641012', 'lat' => 11.0168, 'lng' => 76.9678],
                    ['name' => 'Peelamedu', 'pincode' => '641004', 'lat' => 11.0264, 'lng' => 77.0145],
                ],
            ],

            // ── TELANGANA (TS) ──
            [
                'state_code' => 'TS',
                'district' => 'Hyderabad',
                'city' => 'Hyderabad',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'Abids', 'pincode' => '500001', 'lat' => 17.3850, 'lng' => 78.4744],
                    ['name' => 'Banjara Hills', 'pincode' => '500034', 'lat' => 17.4156, 'lng' => 78.4357],
                    ['name' => 'Jubilee Hills', 'pincode' => '500033', 'lat' => 17.4319, 'lng' => 78.4073],
                    ['name' => 'Somajiguda', 'pincode' => '500082', 'lat' => 17.4264, 'lng' => 78.4589],
                    ['name' => 'Begumpet', 'pincode' => '500016', 'lat' => 17.4448, 'lng' => 78.4664],
                ],
            ],
            [
                'state_code' => 'TS',
                'district' => 'Rangareddy',
                'city' => 'Cyberabad',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'HITEC City', 'pincode' => '500081', 'lat' => 17.4435, 'lng' => 78.3772],
                    ['name' => 'Gachibowli', 'pincode' => '500032', 'lat' => 17.4401, 'lng' => 78.3489],
                    ['name' => 'Madhapur', 'pincode' => '500081', 'lat' => 17.4483, 'lng' => 78.3915],
                    ['name' => 'Kondapur', 'pincode' => '500084', 'lat' => 17.4699, 'lng' => 78.3578],
                ],
            ],

            // ── UTTAR PRADESH (UP) ──
            [
                'state_code' => 'UP',
                'district' => 'Gautam Buddha Nagar',
                'city' => 'Noida',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'Sector 18', 'pincode' => '201301', 'lat' => 28.5708, 'lng' => 77.3260],
                    ['name' => 'Sector 62', 'pincode' => '201309', 'lat' => 28.6280, 'lng' => 77.3649],
                    ['name' => 'Greater Noida West', 'pincode' => '201306', 'lat' => 28.6019, 'lng' => 77.4412],
                ],
            ],
            [
                'state_code' => 'UP',
                'district' => 'Lucknow',
                'city' => 'Lucknow',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Hazratganj', 'pincode' => '226001', 'lat' => 26.8530, 'lng' => 80.9450],
                    ['name' => 'Gomti Nagar', 'pincode' => '226010', 'lat' => 26.8504, 'lng' => 80.9995],
                    ['name' => 'Aliganj', 'pincode' => '226024', 'lat' => 26.8879, 'lng' => 80.9412],
                ],
            ],
            [
                'state_code' => 'UP',
                'district' => 'Varanasi',
                'city' => 'Varanasi',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Sigra', 'pincode' => '221002', 'lat' => 25.3176, 'lng' => 82.9876],
                    ['name' => 'Lanka', 'pincode' => '221005', 'lat' => 25.2818, 'lng' => 82.9992],
                ],
            ],

            // ── RAJASTHAN (RJ) ──
            [
                'state_code' => 'RJ',
                'district' => 'Jaipur',
                'city' => 'Jaipur',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'C Scheme', 'pincode' => '302001', 'lat' => 26.9110, 'lng' => 75.8010],
                    ['name' => 'Malviya Nagar', 'pincode' => '302017', 'lat' => 26.8539, 'lng' => 75.8052],
                    ['name' => 'Vaishali Nagar', 'pincode' => '302021', 'lat' => 26.9112, 'lng' => 75.7410],
                    ['name' => 'Mansarovar', 'pincode' => '302020', 'lat' => 26.8617, 'lng' => 75.7645],
                ],
            ],
            [
                'state_code' => 'RJ',
                'district' => 'Jodhpur',
                'city' => 'Jodhpur',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Shastri Nagar', 'pincode' => '342003', 'lat' => 26.2734, 'lng' => 73.0089],
                    ['name' => 'Ratanada', 'pincode' => '342011', 'lat' => 26.2712, 'lng' => 73.0354],
                ],
            ],

            // ── WEST BENGAL (WB) ──
            [
                'state_code' => 'WB',
                'district' => 'Kolkata',
                'city' => 'Kolkata',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'Park Street', 'pincode' => '700016', 'lat' => 22.5512, 'lng' => 88.3524],
                    ['name' => 'Ballygunge', 'pincode' => '700019', 'lat' => 22.5280, 'lng' => 88.3654],
                    ['name' => 'Salt Lake Sector V', 'pincode' => '700091', 'lat' => 22.5804, 'lng' => 88.4378],
                    ['name' => 'New Town', 'pincode' => '700156', 'lat' => 22.5958, 'lng' => 88.4795],
                    ['name' => 'Alipore', 'pincode' => '700027', 'lat' => 22.5312, 'lng' => 88.3312],
                ],
            ],

            // ── KERALA (KL) ──
            [
                'state_code' => 'KL',
                'district' => 'Ernakulam',
                'city' => 'Kochi',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Panampilly Nagar', 'pincode' => '682036', 'lat' => 9.9634, 'lng' => 76.2941],
                    ['name' => 'Kaloor', 'pincode' => '682017', 'lat' => 9.9984, 'lng' => 76.2945],
                    ['name' => 'Edappally', 'pincode' => '682024', 'lat' => 10.0241, 'lng' => 76.3078],
                    ['name' => 'Kakkanad', 'pincode' => '682030', 'lat' => 10.0159, 'lng' => 76.3419],
                ],
            ],

            // ── MADHYA PRADESH (MP) ──
            [
                'state_code' => 'MP',
                'district' => 'Indore',
                'city' => 'Indore',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Vijay Nagar', 'pincode' => '452010', 'lat' => 22.7533, 'lng' => 75.8937],
                    ['name' => 'Palasia', 'pincode' => '452001', 'lat' => 22.7244, 'lng' => 75.8839],
                    ['name' => 'MG Road', 'pincode' => '452007', 'lat' => 22.7196, 'lng' => 75.8577],
                ],
            ],

            // ── HARYANA (HR) ──
            [
                'state_code' => 'HR',
                'district' => 'Gurugram',
                'city' => 'Gurugram',
                'tier' => 'tier_1',
                'areas' => [
                    ['name' => 'DLF Phase 2 / Cyber City', 'pincode' => '122002', 'lat' => 28.4909, 'lng' => 77.0894],
                    ['name' => 'Golf Course Road / Sector 54', 'pincode' => '122011', 'lat' => 28.4357, 'lng' => 77.1065],
                    ['name' => 'Sohna Road / Sector 48', 'pincode' => '122018', 'lat' => 28.4190, 'lng' => 77.0423],
                ],
            ],

            // ── CHANDIGARH (CH) ──
            [
                'state_code' => 'CH',
                'district' => 'Chandigarh',
                'city' => 'Chandigarh',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Sector 17', 'pincode' => '160017', 'lat' => 30.7410, 'lng' => 76.7850],
                    ['name' => 'Sector 35', 'pincode' => '160035', 'lat' => 30.7241, 'lng' => 76.7667],
                ],
            ],

            // ── BIHAR (BR) ──
            [
                'state_code' => 'BR',
                'district' => 'Patna',
                'city' => 'Patna',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Boring Road', 'pincode' => '800001', 'lat' => 25.6176, 'lng' => 85.1212],
                    ['name' => 'Kankarbagh', 'pincode' => '800020', 'lat' => 25.5941, 'lng' => 85.1578],
                ],
            ],

            // ── ODISHA (OD) ──
            [
                'state_code' => 'OD',
                'district' => 'Khordha',
                'city' => 'Bhubaneswar',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Saheed Nagar', 'pincode' => '751007', 'lat' => 20.2890, 'lng' => 85.8450],
                    ['name' => 'Jayadev Vihar', 'pincode' => '751013', 'lat' => 20.3012, 'lng' => 85.8234],
                ],
            ],

            // ── ASSAM (AS) ──
            [
                'state_code' => 'AS',
                'district' => 'Kamrup Metropolitan',
                'city' => 'Guwahati',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Christian Basti / GS Road', 'pincode' => '781005', 'lat' => 26.1524, 'lng' => 91.7789],
                    ['name' => 'Dispur', 'pincode' => '781006', 'lat' => 26.1430, 'lng' => 91.7898],
                ],
            ],

            // ── GOA (GA) ──
            [
                'state_code' => 'GA',
                'district' => 'North Goa',
                'city' => 'Panaji',
                'tier' => 'tier_3',
                'areas' => [
                    ['name' => 'Miramar', 'pincode' => '403001', 'lat' => 15.4850, 'lng' => 73.8112],
                    ['name' => 'Campal', 'pincode' => '403001', 'lat' => 15.4920, 'lng' => 73.8190],
                ],
            ],

            // ── CHHATTISGARH (CG) ──
            [
                'state_code' => 'CG',
                'district' => 'Raipur',
                'city' => 'Raipur',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Pandri', 'pincode' => '492004', 'lat' => 21.2589, 'lng' => 81.6512],
                    ['name' => 'Shankar Nagar', 'pincode' => '492007', 'lat' => 21.2467, 'lng' => 81.6678],
                ],
            ],

            // ── JHARKHAND (JH) ──
            [
                'state_code' => 'JH',
                'district' => 'Ranchi',
                'city' => 'Ranchi',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Main Road', 'pincode' => '834001', 'lat' => 23.3645, 'lng' => 85.3289],
                    ['name' => 'Lalpur', 'pincode' => '834001', 'lat' => 23.3768, 'lng' => 85.3412],
                ],
            ],

            // ── UTTARAKHAND (UK) ──
            [
                'state_code' => 'UK',
                'district' => 'Dehradun',
                'city' => 'Dehradun',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Rajpur Road', 'pincode' => '248001', 'lat' => 30.3412, 'lng' => 78.0589],
                    ['name' => 'Jakhan', 'pincode' => '248009', 'lat' => 30.3698, 'lng' => 78.0734],
                ],
            ],

            // ── ANDHRA PRADESH (AP) ──
            [
                'state_code' => 'AP',
                'district' => 'Visakhapatnam',
                'city' => 'Visakhapatnam',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'Dwaraka Nagar', 'pincode' => '530016', 'lat' => 17.7289, 'lng' => 83.3089],
                    ['name' => 'Siripuram', 'pincode' => '530003', 'lat' => 17.7212, 'lng' => 83.3190],
                ],
            ],
            [
                'state_code' => 'AP',
                'district' => 'NTR',
                'city' => 'Vijayawada',
                'tier' => 'tier_2',
                'areas' => [
                    ['name' => 'MG Road / Labbipet', 'pincode' => '520010', 'lat' => 16.5062, 'lng' => 80.6480],
                ],
            ],
        ];

        foreach ($hierarchy as $item) {
            $state = $stateMap[$item['state_code']] ?? null;
            if (! $state) {
                continue;
            }

            // District
            $district = GeoDistrict::updateOrCreate(
                ['state_id' => $state->id, 'name' => $item['district']],
                ['is_active' => true]
            );

            // City
            $city = GeoCity::updateOrCreate(
                ['district_id' => $district->id, 'name' => $item['city']],
                [
                    'state_id' => $state->id,
                    'slug' => Str::slug($item['city']),
                    'tier' => $item['tier'],
                    'is_active' => true,
                ]
            );

            // Areas & Pincodes
            foreach ($item['areas'] as $areaData) {
                $area = GeoArea::updateOrCreate(
                    ['city_id' => $city->id, 'name' => $areaData['name']],
                    [
                        'slug' => Str::slug($areaData['name']),
                        'is_active' => true,
                    ]
                );

                GeoPincode::updateOrCreate(
                    [
                        'pincode' => $areaData['pincode'],
                        'area_id' => $area->id,
                        'city_id' => $city->id,
                    ],
                    [
                        'district_id' => $district->id,
                        'state_id' => $state->id,
                        'latitude' => $areaData['lat'] ?? null,
                        'longitude' => $areaData['lng'] ?? null,
                        'is_active' => true,
                    ]
                );
            }
        }
    }
}
