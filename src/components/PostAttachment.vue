<template>
	<hooper>
		<slide v-for="(slide,idx) in attachments" :key="slide.id" :index="idx">
			<img :src="OC.generateUrl('/apps/social/document/get?id=' + slide.id)" />
		</slide>
	</hooper>
</template>

<script>

import { Hooper, Slide } from 'hooper';
import 'hooper/dist/hooper.css';

export default {
	name: 'PostAttachment',
	components: {
		Hooper,
		Slide
	},
	mixins: [],
	props: {
		attachments: Array,
	},
	data() {
		return {
		}
	},
	computed: {
	},
	mounted() {
	},
	methods: {
	}
}
</script>

<style scoped lang="scss">
	.post-attachment {
		display: flex;
		flex-grow: 1;
	}
</style>
